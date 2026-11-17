<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\GuestVerificationOtp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Guest self-service for bookings: prove ownership with a one-time code,
 * review the booking, and cancel it while nobody has checked in yet.
 * The reservation counterpart is GuestReservationManagementService.
 */
class GuestBookingManagementService
{
    private const OTP_PURPOSE = 'booking_manage';

    private const OTP_TTL_MINUTES = 10;

    private const MAX_OTP_ATTEMPTS = 5;

    public function __construct(
        private readonly StaffBookingCancellationService $cancellation,
    ) {}

    /** @return array{booking_id:int, debug_otp:?string} */
    public function requestOtp(string $referenceNumber, string $email): array
    {
        $referenceNumber = strtoupper(trim($referenceNumber));
        $email = strtolower(trim($email));

        if ($referenceNumber === '' || $email === '') {
            throw new InvalidArgumentException('Booking reference number and email are required.');
        }

        $booking = Booking::query()
            ->where('b_ref_no', $referenceNumber)
            ->whereHas('guest', function ($query) use ($email): void {
                $query->whereRaw('LOWER(email) = ?', [$email]);
            })
            ->first();

        if (! $booking) {
            throw new InvalidArgumentException('No booking was found for the reference number and email.');
        }

        $otp = (string) random_int(100000, 999999);

        DB::transaction(function () use ($booking, $email, $otp): void {
            GuestVerificationOtp::query()
                ->where('booking_id', $booking->booking_id)
                ->where('email', $email)
                ->where('purpose', self::OTP_PURPOSE)
                ->whereNull('verified_at')
                ->update(['expires_at' => Carbon::now()->subMinute()]);

            GuestVerificationOtp::query()->create([
                'reservation_id' => null,
                'booking_id' => $booking->booking_id,
                'email' => $email,
                'purpose' => self::OTP_PURPOSE,
                'otp_hash' => Hash::make($otp),
                'attempts' => 0,
                'expires_at' => Carbon::now()->addMinutes(self::OTP_TTL_MINUTES),
            ]);
        });

        $this->sendOtpEmail($email, (string) $booking->b_ref_no, $otp);

        return [
            'booking_id' => (int) $booking->booking_id,
            'debug_otp' => app()->environment('local') ? $otp : null,
        ];
    }

    public function verifyOtp(int $bookingId, string $email, string $otp): Booking
    {
        $email = strtolower(trim($email));
        $otp = trim($otp);

        if ($bookingId < 1 || $email === '' || $otp === '') {
            throw new InvalidArgumentException('OTP verification details are incomplete.');
        }

        $booking = DB::transaction(function () use ($bookingId, $email, $otp): ?Booking {
            $record = GuestVerificationOtp::query()
                ->where('booking_id', $bookingId)
                ->where('email', $email)
                ->where('purpose', self::OTP_PURPOSE)
                ->whereNull('verified_at')
                ->latest('guest_verification_otp_id')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw new InvalidArgumentException('No active OTP request was found. Please request a new OTP.');
            }

            if (Carbon::parse($record->expires_at)->isPast()) {
                throw new InvalidArgumentException('OTP expired. Please request a new OTP.');
            }

            if ($record->attempts >= self::MAX_OTP_ATTEMPTS) {
                throw new InvalidArgumentException('Too many OTP attempts. Please request a new OTP.');
            }

            $record->increment('attempts');

            if (! Hash::check($otp, $record->otp_hash)) {
                return null;
            }

            $record->update(['verified_at' => Carbon::now()]);

            return Booking::query()->findOrFail($bookingId);
        });

        if ($booking === null) {
            throw new InvalidArgumentException('Invalid OTP.');
        }

        return $booking;
    }

    public function cancelBooking(int $bookingId, string $reason): Booking
    {
        $booking = $this->cancellation->cancelForGuest($bookingId, $reason);

        GuestVerificationOtp::query()
            ->where('booking_id', $bookingId)
            ->where('purpose', self::OTP_PURPOSE)
            ->whereNull('verified_at')
            ->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->sendCancelledEmail($booking);

        return $booking;
    }

    private function sendOtpEmail(string $email, string $referenceNumber, string $otp): void
    {
        Mail::send(
            [
                'html' => 'emails.guest.reservation-otp',
                'raw' => "Your Olaer Spring Resort booking OTP is {$otp}. Reference: {$referenceNumber}. This code expires in ".self::OTP_TTL_MINUTES.' minutes.',
            ],
            [
                'code' => $otp,
                'reference' => $referenceNumber,
                'minutes' => self::OTP_TTL_MINUTES,
                'label' => 'Booking',
            ],
            function ($message) use ($email): void {
                $message->to($email)->subject('Olaer Spring Resort Booking OTP');
            }
        );
    }

    private function sendCancelledEmail(Booking $booking): void
    {
        try {
            $email = trim((string) ($booking->guest->email ?? ''));

            if ($email === '') {
                return;
            }

            Mail::raw(
                "Your Olaer Spring Resort booking {$booking->b_ref_no} has been cancelled. "
                .'If you made a payment, please contact the resort cashier with this reference number to ask about a refund. '
                .'Keep this email for your records.',
                function ($message) use ($email, $booking): void {
                    $message->to($email)->subject('Olaer Spring Resort Booking Cancelled - '.$booking->b_ref_no);
                },
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

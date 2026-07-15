<?php

namespace App\Services;

use App\Models\Facility;
use App\Models\FacilityPrice;
use App\Models\FacilityType;
use App\Models\GuestVerificationOtp;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\ReservationExtraGuest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class GuestReservationManagementService
{
    private const OTP_PURPOSE = 'reservation_manage';
    private const OTP_TTL_MINUTES = 10;
    private const MAX_OTP_ATTEMPTS = 5;

    public function __construct(
        private readonly ReservationQuoteService $quoteService,
        private readonly DiscountResolverService $discountResolver,
        private readonly FacilityOccupancyService $occupancy,
        private readonly FacilityScheduleLockService $scheduleLock,
        private readonly GuestConfirmationEmailService $confirmationEmailService,
    ) {}

    /** @return array{reservation_id:int, debug_otp:?string} */
    public function requestOtp(string $referenceNumber, string $email): array
    {
        $referenceNumber = strtoupper(trim($referenceNumber));
        $email = strtolower(trim($email));

        if ($referenceNumber === '' || $email === '') {
            throw new InvalidArgumentException('Reservation reference number and email are required.');
        }

        $reservation = $this->findManageableReservation($referenceNumber, $email);
        $otp = (string) random_int(100000, 999999);

        DB::transaction(function () use ($reservation, $email, $otp): void {
            GuestVerificationOtp::query()
                ->where('reservation_id', $reservation->reservation_id)
                ->where('email', $email)
                ->where('purpose', self::OTP_PURPOSE)
                ->whereNull('verified_at')
                ->update(['expires_at' => Carbon::now()->subMinute()]);

            GuestVerificationOtp::query()->create([
                'reservation_id' => $reservation->reservation_id,
                'email' => $email,
                'purpose' => self::OTP_PURPOSE,
                'otp_hash' => Hash::make($otp),
                'attempts' => 0,
                'expires_at' => Carbon::now()->addMinutes(self::OTP_TTL_MINUTES),
            ]);
        });

        $this->sendOtpEmail($email, $reservation->r_ref_no, $otp);

        return [
            'reservation_id' => (int) $reservation->reservation_id,
            'debug_otp' => app()->environment('local') ? $otp : null,
        ];
    }

    public function verifyOtp(int $reservationId, string $email, string $otp): Reservation
    {
        $email = strtolower(trim($email));
        $otp = trim($otp);

        if ($reservationId < 1 || $email === '' || $otp === '') {
            throw new InvalidArgumentException('OTP verification details are incomplete.');
        }

        return DB::transaction(function () use ($reservationId, $email, $otp): Reservation {
            $record = GuestVerificationOtp::query()
                ->where('reservation_id', $reservationId)
                ->where('email', $email)
                ->where('purpose', self::OTP_PURPOSE)
                ->whereNull('verified_at')
                ->latest('guest_verification_otp_id')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw new InvalidArgumentException('No active OTP request was found. Please request a new OTP.');
            }

            if ($record->expires_at->isPast()) {
                throw new InvalidArgumentException('OTP expired. Please request a new OTP.');
            }

            if ($record->attempts >= self::MAX_OTP_ATTEMPTS) {
                throw new InvalidArgumentException('Too many OTP attempts. Please request a new OTP.');
            }

            $record->increment('attempts');

            if (! Hash::check($otp, $record->otp_hash)) {
                throw new InvalidArgumentException('Invalid OTP.');
            }

            $record->update(['verified_at' => Carbon::now()]);

            return Reservation::query()
                ->with(['guest.address', 'details.facility.facilityType', 'details.discount', 'extraGuests', 'payments'])
                ->findOrFail($reservationId);
        });
    }

    public function facilityTypes(): Collection
    {
        return FacilityType::query()
            ->whereHas('facilities')
            ->orderBy('facility_type')
            ->get();
    }

    public function rateTypesForFacilityType(?int $facilityTypeId): Collection
    {
        if (! $facilityTypeId) {
            return collect();
        }

        return FacilityPrice::query()
            ->select('tbl_facility_price.rate_type')
            ->join('tbl_facility', 'tbl_facility.facility_id', '=', 'tbl_facility_price.facility_id')
            ->where('tbl_facility.facility_type_id', $facilityTypeId)
            ->distinct()
            ->orderBy('tbl_facility_price.rate_type')
            ->pluck('tbl_facility_price.rate_type');
    }

    public function availableFacilities(
        ?int $facilityTypeId,
        ?string $rateType,
        ?string $checkInDate,
        ?string $checkOutDate,
        ?int $ignoreReservationDetailsId = null
    ): Collection {
        if (! $facilityTypeId || blank($rateType) || blank($checkInDate) || blank($checkOutDate)) {
            return collect();
        }

        if ($checkOutDate <= $checkInDate) {
            return collect();
        }

        $facilities = Facility::query()
            ->with(['facilityType', 'prices'])
            ->where('facility_type_id', $facilityTypeId)
            ->where('facility_status', 'Available')
            ->whereHas('prices', function ($query) use ($rateType): void {
                $query->where('rate_type', $rateType);
            })
            ->orderBy('facility_name')
            ->get();

        return $facilities
            ->filter(fn (Facility $facility): bool => $this->isFacilityAvailable(
                (int) $facility->facility_id,
                (string) $checkInDate,
                (string) $checkOutDate,
                $ignoreReservationDetailsId,
            ))
            ->values();
    }

    public function quotePreview(
        ?int $facilityId,
        ?string $rateType,
        ?string $checkInDate,
        ?string $checkOutDate,
        int $extraGuestCount = 0,
        ?int $preferredDiscountId = null,
        ?int $totalGuestCount = null,
    ): ?array {
        if (! $facilityId || blank($rateType) || blank($checkInDate) || blank($checkOutDate)) {
            return null;
        }

        $discount = $this->discountResolver->resolveForFacility((int) $facilityId, (string) $checkInDate, $preferredDiscountId);

        return $this->quoteService->quote(
            facilityId: (int) $facilityId,
            rateType: (string) $rateType,
            checkInDate: (string) $checkInDate,
            checkOutDate: (string) $checkOutDate,
            discountId: $discount?->discount_id,
            extraGuestCount: $extraGuestCount,
            totalGuestCount: $totalGuestCount,
        );
    }

    public function updateReservation(int $reservationId, array $data): Reservation
    {
        $reservation = DB::transaction(function () use ($reservationId, $data): Reservation {
            $reservation = Reservation::query()
                ->with(['details', 'payments'])
                ->lockForUpdate()
                ->findOrFail($reservationId);

            $this->guardActiveReservation($reservation);
            $this->guardNoVerifiedPayments($reservation);

            $detail = $reservation->details()->lockForUpdate()->first();

            if (! $detail) {
                throw new InvalidArgumentException('Reservation has no facility details to update.');
            }

            $facilityId = (int) $data['facility_id'];
            $rateType = (string) $data['rate_type'];
            $checkInDate = (string) $data['check_in_date'];
            $checkOutDate = (string) $data['check_out_date'];

            $this->scheduleLock->lockOne($facilityId);

            $totalGuestCount = max(
                1,
                (int) ($data['total_guest_count'] ?? 1),
            );
            $extraGuests = $this->cleanExtraGuests(
                $data['extra_guests'] ?? [],
            );
            $occupancy = $this->occupancy->forFacilityId(
                $facilityId,
                $totalGuestCount,
            );
            $this->occupancy->assertNamedPaidExtraGuests(
                $extraGuests,
                $occupancy['paid_extra_guest_count'],
            );
            $extraGuestCount =
                $occupancy['paid_extra_guest_count'];

            if (! $this->isFacilityAvailable($facilityId, $checkInDate, $checkOutDate, (int) $detail->reservation_details_id)) {
                throw new InvalidArgumentException('Selected facility is not available for the selected date range.');
            }

            $discount = $this->discountResolver->resolveForFacility(
                $facilityId,
                $checkInDate,
                $detail->discount_id ? (int) $detail->discount_id : null,
            );

            $quote = $this->quoteService->quote(
                facilityId: $facilityId,
                rateType: $rateType,
                checkInDate: $checkInDate,
                checkOutDate: $checkOutDate,
                discountId: $discount?->discount_id,
                totalGuestCount: $totalGuestCount,
            );

            $reservation->update([
                'total_price' => $quote['total_price'],
                'amount_due' => $quote['amount_due'],
                'no_of_extra_guests' => $extraGuestCount,
                'total_guest_count' => $totalGuestCount,
            ]);

            $detail->update([
                'facility_id' => $facilityId,
                'rate_type' => $rateType,
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'discount_id' => $discount?->discount_id,
            ]);

            ReservationExtraGuest::query()->where('reservation_id', $reservation->reservation_id)->delete();

            foreach ($extraGuests as $extraGuest) {
                ReservationExtraGuest::query()->create([
                    'reservation_id' => $reservation->reservation_id,
                    'first_name' => $extraGuest['first_name'],
                    'middle_name' => $extraGuest['middle_name'],
                    'last_name' => $extraGuest['last_name'],
                ]);
            }

            return $reservation->fresh(['guest.address', 'details.facility.facilityType', 'details.discount', 'extraGuests', 'payments']);
        });

        $this->confirmationEmailService->sendReservationUpdated($reservation);

        return $reservation;
    }

    public function cancelReservation(int $reservationId, string $reason): Reservation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Cancellation reason is required.');
        }

        $reservation = DB::transaction(function () use ($reservationId, $reason): Reservation {
            $reservation = Reservation::query()
                ->with('payments')
                ->lockForUpdate()
                ->findOrFail($reservationId);

            $this->guardActiveReservation($reservation);
            $this->guardNoVerifiedPayments($reservation);

            $reservation->update([
                'status' => 'Cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => Carbon::today()->toDateString(),
            ]);

            return $reservation->fresh(['guest.address', 'details.facility.facilityType', 'details.discount', 'extraGuests', 'payments']);
        });

        $this->confirmationEmailService->sendReservationCancelled($reservation);

        return $reservation;
    }

    private function findManageableReservation(string $referenceNumber, string $email): Reservation
    {
        $reservation = Reservation::query()
            ->with(['guest', 'payments'])
            ->where('r_ref_no', $referenceNumber)
            ->whereHas('guest', function ($query) use ($email): void {
                $query->whereRaw('LOWER(email) = ?', [$email]);
            })
            ->first();

        if (! $reservation) {
            throw new InvalidArgumentException('No reservation was found for the reference number and email.');
        }

        $this->guardActiveReservation($reservation);
        $this->guardNoVerifiedPayments($reservation);

        return $reservation;
    }

    private function guardActiveReservation(Reservation $reservation): void
    {
        if ((string) $reservation->status !== 'Active') {
            throw new InvalidArgumentException('Only active reservations can be managed online.');
        }
    }

    private function guardNoVerifiedPayments(Reservation $reservation): void
    {
        $hasVerifiedPayment = $reservation->payments()
            ->where('payment_status', 'Verified')
            ->exists();

        if ($hasVerifiedPayment) {
            throw new InvalidArgumentException('This reservation already has verified payment. Please contact the cashier for changes.');
        }
    }

    private function isFacilityAvailable(int $facilityId, string $checkInDate, string $checkOutDate, ?int $ignoreReservationDetailsId = null): bool
    {
        if ($checkOutDate <= $checkInDate) {
            return false;
        }

        $facility = Facility::query()->find($facilityId);

        if (! $facility || $facility->facility_status !== 'Available') {
            return false;
        }

        $reservationConflict = ReservationDetail::query()
            ->join('tbl_reservation', 'tbl_reservation.reservation_id', '=', 'tbl_reservation_details.reservation_id')
            ->where('tbl_reservation_details.facility_id', $facilityId)
            ->where('tbl_reservation_details.check_in_date', '<', $checkOutDate)
            ->where('tbl_reservation_details.check_out_date', '>', $checkInDate)
            ->whereNotIn('tbl_reservation.status', ['Cancelled', 'Converted', 'No-show'])
            ->when($ignoreReservationDetailsId, function ($query) use ($ignoreReservationDetailsId): void {
                $query->where('tbl_reservation_details.reservation_details_id', '!=', $ignoreReservationDetailsId);
            })
            ->exists();

        if ($reservationConflict) {
            return false;
        }

        return ! DB::table('tbl_booking_details')
            ->join('tbl_booking', 'tbl_booking.booking_id', '=', 'tbl_booking_details.booking_id')
            ->where('tbl_booking_details.facility_id', $facilityId)
            ->where('tbl_booking_details.check_in_date', '<', $checkOutDate)
            ->where('tbl_booking_details.check_out_date', '>', $checkInDate)
            ->whereNotIn('tbl_booking_details.status', ['Cancelled', 'Checked-out', 'Transferred', 'Payment Rejected'])
            ->whereNotIn('tbl_booking.status', ['Cancelled', 'Payment Rejected'])
            ->exists();
    }

    private function cleanExtraGuests(array $extraGuests): array
    {
        $clean = [];

        foreach ($extraGuests as $extraGuest) {
            $firstName = trim((string) ($extraGuest['first_name'] ?? ''));
            $middleName = trim((string) ($extraGuest['middle_name'] ?? ''));
            $lastName = trim((string) ($extraGuest['last_name'] ?? ''));

            if ($firstName === '' && $middleName === '' && $lastName === '') {
                continue;
            }

            if ($firstName === '' || $lastName === '') {
                throw new InvalidArgumentException('Each extra guest must have a first name and last name.');
            }

            $clean[] = [
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
            ];
        }

        return $clean;
    }

    private function sendOtpEmail(string $email, string $referenceNumber, string $otp): void
    {
        Mail::raw(
            "Your Olaer Spring Resort reservation OTP is {$otp}. Reference: {$referenceNumber}. This code expires in " . self::OTP_TTL_MINUTES . ' minutes.',
            function ($message) use ($email): void {
                $message->to($email)->subject('Olaer Spring Resort Reservation OTP');
            }
        );
    }
}

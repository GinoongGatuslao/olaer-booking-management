<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\BookingExtraGuest;
use App\Models\Guest;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PublicBookingWorkflowService
{
    public function __construct(
        private readonly BookingAvailabilityService $availability,
        private readonly BookingQuoteService $quoteService,
        private readonly PublicBookingSearchService $searchService,
        private readonly GuestConfirmationEmailService $confirmationEmailService,
    ) {}

    public function createGuestBookingWithPendingGcash(array $data): Booking
    {
        $booking = DB::transaction(function () use ($data): Booking {
            $facilityId = (int) $data['facility_id'];
            $rateType = (string) $data['rate_type'];
            $checkInDate = (string) $data['check_in_date'];
            $checkOutDate = (string) $data['check_out_date'];
            $extraGuests = $this->cleanExtraGuests($data['extra_guests'] ?? []);
            $extraGuestCount = count($extraGuests);
            $maxExtraGuests = $this->searchService->maxExtraGuests($facilityId);

            if ($extraGuestCount > $maxExtraGuests) {
                throw new InvalidArgumentException("This facility only allows {$maxExtraGuests} paid extra guest(s).");
            }

            $this->availability->assertFacilityAvailable($facilityId, $checkInDate, $checkOutDate);

            $quote = $this->quoteService->quote(
                facilityId: $facilityId,
                rateType: $rateType,
                extraGuestCount: $extraGuestCount,
                discountId: null,
            );

            $paymentAmount = round((float) $data['payment_amount'], 2);
            $total = round((float) $quote['total'], 2);

            if ($paymentAmount !== $total) {
                throw new InvalidArgumentException('Online booking requires exact full GCash payment before submission. Staff will still verify the proof.');
            }

            $mode = ModeOfPayment::query()
                ->whereRaw('LOWER(mode_of_payment) = ?', ['gcash'])
                ->first();

            if (! $mode) {
                throw new InvalidArgumentException('GCash payment mode is not configured. Seed or add GCash in Mode of Payment first.');
            }

            $referenceNumber = trim((string) ($data['reference_number'] ?? ''));

            if ($referenceNumber === '') {
                throw new InvalidArgumentException('GCash reference number is required.');
            }

            $proofPath = trim((string) ($data['proof_of_payment_path'] ?? ''));

            if ($proofPath === '') {
                throw new InvalidArgumentException('Proof of payment is required.');
            }

            $address = Address::query()->create([
                'purok' => $data['purok'] ?? null,
                'province' => $data['province'],
                'city' => $data['city'],
                'barangay' => $data['barangay'] ?? null,
            ]);

            $guest = Guest::query()->create([
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'contact_no' => $data['contact_no'],
                'email' => $data['email'],
                'address_id' => $address->address_id,
            ]);

            $booking = Booking::query()->create([
                'b_ref_no' => $this->newBookingReference(),
                'guest_id' => $guest->guest_id,
                'booking_date' => Carbon::today()->toDateString(),
                'no_of_extra_guests' => $extraGuestCount,
                'total_price' => $total,
                // Keep amount_due until cashier verifies the GCash proof.
                'amount_due' => $total,
                'user_id' => null,
                'reservation_id' => null,
                'entrance_slip_id' => null,
                'status' => 'Pending Verification',
            ]);

            BookingDetail::query()->create([
                'booking_id' => $booking->booking_id,
                'facility_id' => $facilityId,
                'rate_type' => $rateType,
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'check_in_time' => $this->normalizeTime($data['check_in_time'] ?? '12:00'),
                'status' => 'Pending Verification',
                'discount_id' => null,
                'user_id' => null,
                'base_price' => $quote['base_price'] ?? null,
                'discount_amount' => $quote['discount_amount'] ?? 0.00,
                'extra_guest_fee' => $quote['extra_guest_fee'] ?? 0.00,
                'line_total' => $total,
            ]);

            foreach ($extraGuests as $extraGuest) {
                BookingExtraGuest::query()->create([
                    'booking_id' => $booking->booking_id,
                    'first_name' => $extraGuest['first_name'],
                    'middle_name' => $extraGuest['middle_name'] ?? null,
                    'last_name' => $extraGuest['last_name'],
                ]);
            }

            Payment::query()->create([
                'p_ref_no' => $this->newPaymentReference(),
                'booking_id' => $booking->booking_id,
                'reservation_id' => null,
                'entrance_slip_id' => null,
                'mode_of_payment_id' => $mode->mode_of_payment_id,
                'reference_number' => $referenceNumber,
                'proof_of_payment_path' => $proofPath,
                'amount_paid' => $paymentAmount,
                'date_paid' => Carbon::today()->toDateString(),
                'user_id' => null,
                'payment_status' => 'Pending',
                'verified_by_user_id' => null,
                'verified_at' => null,
            ]);

            return $booking->fresh([
                'guest.address',
                'details.facility.facilityType',
                'extraGuests',
                'payments.modeOfPayment',
            ]);
        });

        $this->confirmationEmailService->sendBookingSubmitted($booking);

        return $booking;
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

    private function normalizeTime(string $time): string
    {
        $time = trim($time);

        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            return $time . ':00';
        }

        return $time;
    }

    private function newBookingReference(): string
    {
        do {
            $reference = 'B' . now()->format('ymdHis') . strtoupper(Str::random(4));
        } while (Booking::query()->where('b_ref_no', $reference)->exists());

        return $reference;
    }

    private function newPaymentReference(): string
    {
        do {
            $reference = 'P' . now()->format('ymdHis') . strtoupper(Str::random(4));
        } while (Payment::query()->where('p_ref_no', $reference)->exists());

        return $reference;
    }
}

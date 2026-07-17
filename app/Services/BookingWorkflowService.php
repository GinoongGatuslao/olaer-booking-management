<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\BookingExtraGuest;
use App\Models\Facility;
use App\Models\Guest;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BookingWorkflowService
{
    public function __construct(
        private readonly BookingAvailabilityService $availability,
        private readonly BookingQuoteService $quoteService,
        private readonly FacilityOccupancyService $occupancy,
        private readonly FacilityScheduleLockService $scheduleLock,
        private readonly GcashReferenceIntegrityService $gcashReferences,
    ) {}

    public function createBooking(array $data): Booking
    {
        return DB::transaction(function () use ($data): Booking {
            $facilityId = (int) $data['facility_id'];
            $checkInDate = (string) $data['check_in_date'];
            $checkOutDate = (string) $data['check_out_date'];
            $rateType = (string) $data['rate_type'];
            $discountId = filled($data['discount_id'] ?? null) ? (int) $data['discount_id'] : null;

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

            $this->availability->assertFacilityAvailable(
                $facilityId,
                $checkInDate,
                $checkOutDate,
            );

            $quote = $this->quoteService->quote(
                facilityId: $facilityId,
                rateType: $rateType,
                discountId: $discountId,
                totalGuestCount: $totalGuestCount,
            );
            $paymentAmount = round((float) $data['payment_amount'], 2);

            if ($paymentAmount !== round((float) $quote['total'], 2)) {
                throw new InvalidArgumentException('Booking requires exact full payment before confirmation.');
            }

            $mode = ModeOfPayment::query()->findOrFail((int) $data['mode_of_payment_id']);
            $referenceNumber = trim((string) ($data['reference_number'] ?? ''));

            if (strtolower($mode->mode_of_payment) === 'gcash') {
                $referenceNumber = $this->gcashReferences
                    ->assertAvailable($referenceNumber);
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
                'email' => $data['email'] ?? null,
                'address_id' => $address->address_id,
            ]);

            $booking = Booking::query()->create([
                'b_ref_no' => $this->newReference('B'),
                'guest_id' => $guest->guest_id,
                'booking_date' => Carbon::now()->toDateString(),
                'no_of_extra_guests' => $extraGuestCount,
                'total_guest_count' => $totalGuestCount,
                'total_price' => $quote['total'],
                'amount_due' => 0.00,
                'user_id' => (int) $data['user_id'],
                'reservation_id' => $data['reservation_id'] ?? null,
                'entrance_slip_id' => $data['entrance_slip_id'] ?? null,
            ]);

            BookingDetail::query()->create([
                'booking_id' => $booking->booking_id,
                'facility_id' => $facilityId,
                'rate_type' => $rateType,
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'check_in_time' => $this->normalizeTime($data['check_in_time'] ?? '12:00'),
                'status' => 'Booked',
                'discount_id' => $discountId,
                'user_id' => (int) $data['user_id'],
                'base_price' => $quote['base_price'],
                'discount_amount' => $quote['discount_amount'],
                'extra_guest_fee' => $quote['extra_guest_fee'],
                'line_total' => $quote['total'],
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
                'p_ref_no' => $this->newReference('P'),
                'booking_id' => $booking->booking_id,
                'reservation_id' => null,
                'entrance_slip_id' => null,
                'mode_of_payment_id' => $mode->mode_of_payment_id,
                'reference_number' => $referenceNumber !== '' ? $referenceNumber : null,
                'amount_paid' => $paymentAmount,
                'date_paid' => Carbon::now(),
                'user_id' => (int) $data['user_id'],
                'payment_status' => 'verified',
                'verified_by_user_id' => (int) $data['user_id'],
                'verified_at' => Carbon::now(),
            ]);

            return $booking->fresh(['guest', 'details.facility', 'payments']);
        });
    }

    public function rescheduleBookingDetail(int $bookingDetailsId, string $newCheckInDate): void
    {
        DB::transaction(function () use ($bookingDetailsId, $newCheckInDate): void {
            $detail = BookingDetail::query()
                ->lockForUpdate()
                ->findOrFail($bookingDetailsId);

            $this->scheduleLock->lockOne(
                (int) $detail->facility_id,
            );

            $oldCheckIn = Carbon::parse($detail->check_in_date);
            $oldCheckOut = Carbon::parse($detail->check_out_date);
            $days = max(1, $oldCheckIn->diffInDays($oldCheckOut));
            $newCheckOutDate = Carbon::parse($newCheckInDate)->addDays($days)->toDateString();

            $this->guardEditableBookingDetail($detail);
            $this->availability->assertFacilityAvailable(
                (int) $detail->facility_id,
                $newCheckInDate,
                $newCheckOutDate,
                (int) $detail->booking_details_id
            );

            $detail->update([
                'check_in_date' => $newCheckInDate,
                'check_out_date' => $newCheckOutDate,
                'status' => 'Rescheduled',
            ]);
        });
    }

    public function transferBookingDetail(int $bookingDetailsId, int $newFacilityId): void
    {
        DB::transaction(function () use ($bookingDetailsId, $newFacilityId): void {
            $detail = BookingDetail::query()
                ->lockForUpdate()
                ->findOrFail($bookingDetailsId);

            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail((int) $detail->booking_id);

            $facilities = $this->scheduleLock
                ->lockMany([
                    (int) $detail->facility_id,
                    $newFacilityId,
                ])
                ->load('facilityType')
                ->keyBy('facility_id');

            $oldFacility = $facilities->get(
                (int) $detail->facility_id,
            );
            $newFacility = $facilities->get(
                $newFacilityId,
            );

            $detail->setRelation('booking', $booking);
            $detail->setRelation('facility', $oldFacility);

            $this->guardEditableBookingDetail($detail);

            if ((int) $oldFacility->facility_type_id !== (int) $newFacility->facility_type_id) {
                throw new InvalidArgumentException('Facility transfer must be within the same facility type.');
            }

            $totalGuestCount = (int) (
                $booking->total_guest_count
                ?: $this->occupancy->legacyTotalGuestCount(
                    $oldFacility,
                    (int) $booking->no_of_extra_guests,
                )
            );

            $this->occupancy->forFacility(
                $newFacility,
                $totalGuestCount,
            );

            $this->availability->assertFacilityAvailable(
                $newFacilityId,
                (string) $detail->check_in_date,
                (string) $detail->check_out_date,
                (int) $detail->booking_details_id
            );

            $oldPrice = $this->quoteService->priceForFacilityRate((int) $detail->facility_id, (string) $detail->rate_type);
            $newPrice = $this->quoteService->priceForFacilityRate($newFacilityId, (string) $detail->rate_type);
            $upgradeCharge = max(0, round($newPrice - $oldPrice, 2));

            $newLineTotal = $detail->line_total !== null
                ? round((float) $detail->line_total + $upgradeCharge, 2)
                : null;

            $detail->update([
                'facility_id' => $newFacilityId,
                'status' => 'Transferred',
                'base_price' => $detail->base_price !== null ? round((float) $detail->base_price + $upgradeCharge, 2) : null,
                'line_total' => $newLineTotal,
            ]);

            if ($upgradeCharge > 0) {
                $booking->increment('total_price', $upgradeCharge);
                $booking->increment('amount_due', $upgradeCharge);
            }
        });
    }

    public function extendCottageDayRate(int $bookingDetailsId): void
    {
        DB::transaction(function () use ($bookingDetailsId): void {
            $detail = BookingDetail::query()->with(['booking', 'facility.facilityType'])->findOrFail($bookingDetailsId);
            $facilityType = strtolower((string) optional($detail->facility->facilityType)->facility_type);

            $this->guardEditableBookingDetail($detail);

            if ($facilityType !== 'cottage') {
                throw new InvalidArgumentException('Only cottage bookings can use the day-rate extension rule.');
            }

            if (strtolower((string) $detail->rate_type) !== 'day rate') {
                throw new InvalidArgumentException('Only Day Rate cottage bookings can be extended using this rule.');
            }

            $charge = BookingQuoteService::COTTAGE_DAY_TO_NIGHT_EXTENSION_FEE;

            $detail->update([
                'rate_type' => 'Day + Night Extension',
                'status' => 'Extended',
                'extra_guest_fee' => round((float) ($detail->extra_guest_fee ?? 0) + $charge, 2),
                'line_total' => $detail->line_total !== null ? round((float) $detail->line_total + $charge, 2) : null,
            ]);

            $detail->booking->increment('total_price', $charge);
            $detail->booking->increment('amount_due', $charge);
        });
    }

    private function guardEditableBookingDetail(BookingDetail $detail): void
    {
        $lockedStatuses = ['Checked-in', 'Checked-out', 'Cancelled'];

        if (in_array((string) $detail->status, $lockedStatuses, true)) {
            throw new InvalidArgumentException('This booking can no longer be modified because it is already checked-in, checked-out, or cancelled.');
        }
    }

    private function normalizeTime(string $time): string
    {
        $time = trim($time);

        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            return $time . ':00';
        }

        return $time;
    }

    private function cleanExtraGuests(array $extraGuests): array
    {
        $clean = [];

        foreach ($extraGuests as $extraGuest) {
            $firstName = trim((string) ($extraGuest['first_name'] ?? ''));
            $lastName = trim((string) ($extraGuest['last_name'] ?? ''));

            if ($firstName === '' && $lastName === '') {
                continue;
            }

            if ($firstName === '' || $lastName === '') {
                throw new InvalidArgumentException('Each extra guest must have a first name and last name.');
            }

            $clean[] = [
                'first_name' => $firstName,
                'middle_name' => trim((string) ($extraGuest['middle_name'] ?? '')) ?: null,
                'last_name' => $lastName,
            ];
        }

        return $clean;
    }

    private function newReference(string $prefix): string
    {
        return $prefix . now()->format('ymdHis') . strtoupper(Str::random(4));
    }
}

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

class BookingWorkflowService
{
    public function __construct(
        private readonly BookingAvailabilityService $availability,
        private readonly BookingQuoteService $quoteService,
        private readonly FacilityOccupancyService $occupancy,
        private readonly FacilityScheduleLockService $scheduleLock,
        private readonly GcashReferenceIntegrityService $gcashReferences,
        private readonly DetailExtraGuestService $detailGuests,
        private readonly DecimalMoneyService $money,
        private readonly FacilityProductConfigurationService $products,
    ) {}

    /** @param array<string, mixed> $data */
    public function createBooking(array $data): Booking
    {
        return DB::transaction(function () use ($data): Booking {
            $facilityId = (int) $data['facility_id'];
            $checkInDate = (string) $data['check_in_date'];
            $checkOutDate = (string) $data['check_out_date'];
            $rateType = (string) $data['rate_type'];
            $discountId = filled($data['discount_id'] ?? null) ? (int) $data['discount_id'] : null;

            $this->scheduleLock->lockOne($facilityId);

            $totalGuestCount = (int) ($data['total_guest_count'] ?? 0);
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
            $paymentAmount = $this->money->normalize(
                $data['payment_amount'],
            );

            if (! $this->money->equals($paymentAmount, $quote['total'])) {
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
                'status' => 'Booked',
            ]);

            $detail = BookingDetail::query()->create([
                'booking_id' => $booking->booking_id,
                'facility_id' => $facilityId,
                'rate_type' => $quote['rate_type'],
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'check_in_time' => $this->normalizeTime($data['check_in_time'] ?? '12:00'),
                'status' => 'Booked',
                'discount_id' => $discountId,
                'user_id' => (int) $data['user_id'],
                ...$quote['detail_snapshot'],
            ]);

            $this->detailGuests->createForBooking(
                $booking,
                $detail,
                $extraGuests,
            );

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
                'payment_status' => 'Verified',
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
                ->with('booking')
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
                ->load(['facilityType', 'facilityProduct.productRates'])
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

            $totalGuestCount = (int) ($detail->guest_count ?? $booking->total_guest_count);
            $destinationQuote = $this->quoteService->quote(
                facilityId: $newFacilityId,
                rateType: (string) $detail->rate_type,
                discountId: null,
                totalGuestCount: $totalGuestCount,
            );
            $destinationProduct = $this->products->productFor($newFacility);
            $oldPrice = $detail->unit_rate
                ?? $this->quoteService->priceForFacilityRate((int) $detail->facility_id, (string) $detail->rate_type);
            $newPrice = $this->money->normalize((string) $destinationQuote['detail_snapshot']['unit_rate']);

            if ($this->money->compare($newPrice, $oldPrice) === -1) {
                throw new InvalidArgumentException('Transfers to a lower-priced facility require the refund workflow.');
            }

            if ($detail->base_price === null
                || $detail->discount_amount === null
                || $detail->extra_guest_fee === null
            ) {
                throw new InvalidArgumentException('The historical booking price is incomplete and must be reviewed before transfer.');
            }

            $basePrice = $this->money->normalize($detail->base_price);
            $discountAmount = $this->money->normalize($detail->discount_amount);
            $extraGuestFee = $this->money->normalize($detail->extra_guest_fee);
            $discountId = $detail->discount_id ? (int) $detail->discount_id : null;
            $discountRate = $detail->discount_rate;

            if (($discountId === null && (! $this->money->equals($discountAmount, '0.00')
                    || ($discountRate !== null && bccomp((string) $discountRate, '0.000000', 6) !== 0)))
                || ($discountId !== null && ($this->money->compare($discountAmount, '0.00') !== 1
                    || ($discountRate !== null && bccomp((string) $discountRate, '0.000000', 6) !== 1)))
            ) {
                throw new InvalidArgumentException('The historical booking discount is incomplete and must be reviewed before transfer.');
            }

            if (! $this->products->isRoomProduct($destinationProduct)
                && ! $this->money->equals($extraGuestFee, '0.00')
            ) {
                throw new InvalidArgumentException('Non-room transfers cannot contain room extra-guest charges.');
            }

            $reconstructedLineTotal = $this->money->maxZero(
                $this->money->subtract(
                    $this->money->add($basePrice, $extraGuestFee),
                    $discountAmount,
                ),
            );
            $oldLineTotal = $detail->line_total === null
                ? $reconstructedLineTotal
                : $this->money->normalize($detail->line_total);

            if (! $this->money->equals($oldLineTotal, $reconstructedLineTotal)) {
                throw new InvalidArgumentException('The historical booking price is internally inconsistent and must be reviewed before transfer.');
            }

            $transferSurcharge = $this->money->subtract($newPrice, $oldPrice);
            $newBasePrice = $this->money->add($basePrice, $transferSurcharge);
            $newLineTotal = $this->money->add($oldLineTotal, $transferSurcharge);
            $reconciledNewLineTotal = $this->money->maxZero(
                $this->money->subtract(
                    $this->money->add($newBasePrice, $extraGuestFee),
                    $discountAmount,
                ),
            );

            if (! $this->money->equals($newLineTotal, $reconciledNewLineTotal)) {
                throw new InvalidArgumentException('The historical booking discount cannot be represented truthfully after transfer.');
            }

            $detail->update([
                'facility_id' => $newFacilityId,
                'rate_type' => $destinationQuote['rate_type'],
                'status' => 'Transferred',
                ...$destinationQuote['detail_snapshot'],
                'discount_id' => $discountId,
                'base_price' => $newBasePrice,
                'discount_rate' => $discountId === null
                    ? ($discountRate ?? '0.000000')
                    : $discountRate,
                'discount_amount' => $discountAmount,
                'extra_guest_fee' => $extraGuestFee,
                'line_total' => $newLineTotal,
            ]);

            if ($this->money->compare($transferSurcharge, '0.00') === 1) {
                $booking->update([
                    'total_price' => $this->money->add(
                        $booking->total_price,
                        $transferSurcharge,
                    ),
                    'amount_due' => $this->money->add(
                        $booking->amount_due,
                        $transferSurcharge,
                    ),
                ]);
            }
        });
    }

    public function extendCottageDayRate(int $bookingDetailsId): void
    {
        DB::transaction(function () use ($bookingDetailsId): void {
            $detail = BookingDetail::query()
                ->with(['booking', 'facility.facilityType'])
                ->lockForUpdate()
                ->findOrFail($bookingDetailsId);

            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail((int) $detail->booking_id);

            $detail->setRelation('booking', $booking);

            $this->scheduleLock->lockOne(
                (int) $detail->facility_id,
            );

            $this->guardEditableBookingDetail($detail);

            $product = $this->products->productFor($detail->facility);

            if (! str_starts_with($product->product_code->value, 'COTTAGE_')) {
                throw new InvalidArgumentException('Only cottage bookings can use the day-rate extension rule.');
            }

            if ($detail->getRawOriginal('rate_code') !== 'DAY') {
                throw new InvalidArgumentException('Only Day Rate cottage bookings can be extended using this rule.');
            }

            $extraGuests = BookingExtraGuest::query()
                ->where('booking_details_id', $detail->booking_details_id)
                ->lockForUpdate()
                ->get();

            if ($extraGuests->isNotEmpty() || ! $this->money->equals($detail->extra_guest_fee ?? '0.00', '0.00')) {
                throw new InvalidArgumentException('Cottage details cannot contain room extra-guest charges or records.');
            }

            if (
                $detail->base_price === null
                || $detail->discount_amount === null
                || $detail->extra_guest_fee === null
            ) {
                throw new InvalidArgumentException('The existing cottage pricing snapshot is incomplete and cannot be extended safely.');
            }

            $discountAmount = $this->money->normalize($detail->discount_amount);
            $existingLineTotal = $this->money->subtract(
                $this->money->add($detail->base_price, $detail->extra_guest_fee),
                $discountAmount,
            );

            if (
                $this->money->compare($existingLineTotal, '0.00') === -1
                || ($detail->line_total !== null && ! $this->money->equals($detail->line_total, $existingLineTotal))
            ) {
                throw new InvalidArgumentException('The existing cottage pricing snapshot does not reconcile and cannot be extended safely.');
            }

            $nightRate = $this->products->rateFor($detail->facility, 'Night');
            $bothRate = $this->products->rateFor($detail->facility, 'Both');
            $newBasePrice = $this->money->add($detail->base_price, $nightRate->amount);

            if (! $this->money->equals($newBasePrice, $bothRate->amount)) {
                throw new InvalidArgumentException('The approved Both rate must equal the booked Day base plus the current Night rate.');
            }

            $newLineTotal = $this->money->add($existingLineTotal, $nightRate->amount);
            $discountRate = $this->money->equals($discountAmount, '0.00')
                ? '0.000000'
                : null;
            $snapshot = $this->products->detailSnapshot(
                facility: $detail->facility,
                rate: $bothRate,
                guestCount: (int) ($detail->guest_count ?? $booking->total_guest_count),
                basePrice: $newBasePrice,
                discountRate: $discountRate,
                discountAmount: $discountAmount,
                extraGuestFee: '0.00',
                lineTotal: $newLineTotal,
            );

            $detail->update([
                'rate_type' => $this->products->canonicalRateType($bothRate),
                'status' => 'Extended',
                ...$snapshot,
            ]);

            $booking->update([
                'total_price' => $this->money->add(
                    $booking->total_price,
                    $nightRate->amount,
                ),
                'amount_due' => $this->money->add(
                    $booking->amount_due,
                    $nightRate->amount,
                ),
            ]);
        });
    }

    private function guardEditableBookingDetail(BookingDetail $detail): void
    {
        $lockedDetailStatuses = [
            'Checked-in',
            'Checked-out',
            'Cancelled',
            'Payment Rejected',
            'Rejected',
        ];

        if (in_array((string) $detail->status, $lockedDetailStatuses, true)) {
            throw new InvalidArgumentException(
                'This booking detail can no longer be modified because it is already locked by its current workflow status.'
            );
        }

        $bookingStatus = (string) $detail->booking?->status;

        $lockedBookingStatuses = [
            'Cancelled',
            'Checked-out',
            'Payment Rejected',
            'Rejected',
            'Pending Verification',
        ];

        if (in_array($bookingStatus, $lockedBookingStatuses, true)) {
            throw new InvalidArgumentException(
                'This booking can no longer be modified because the parent booking is not in an editable state.'
            );
        }
    }

    private function normalizeTime(string $time): string
    {
        $time = trim($time);

        if (preg_match('/^\d{2}:\d{2}$/', $time) === 1) {
            return $time.':00';
        }

        return $time;
    }

    /**
     * @param  array<int, array<string, mixed>>  $extraGuests
     * @return array<int, array{first_name: string, middle_name: ?string, last_name: string}>
     */
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
        return $prefix.now()->format('ymdHis').strtoupper(Str::random(4));
    }
}

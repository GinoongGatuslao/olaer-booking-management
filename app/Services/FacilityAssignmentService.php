<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\Facility;
use App\Models\FacilityRequirementGroup;
use App\Models\FacilityRequirementIntent;
use App\Models\FacilityScheduleBlock;
use App\Models\Guest;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\TransactionAdjustment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FacilityAssignmentService
{
    public function __construct(
        private readonly FacilityProductConfigurationService $products,
        private readonly FacilityScheduleBlockService $scheduleBlocks,
        private readonly ReservationQuoteService $quotes,
        private readonly DiscountResolverService $discounts,
        private readonly DetailExtraGuestService $detailGuests,
        private readonly RoomOccupantService $roomOccupants,
        private readonly DecimalMoneyService $money,
        private readonly GuestConfirmationEmailService $confirmationEmail,
        private readonly GcashReferenceIntegrityService $gcashReferences,
        private readonly EntranceSlipWorkflowService $entranceSlips,
    ) {}

    /** @param array<string, mixed> $guestData */
    public function createReservation(
        FacilityRequirementIntent $intent,
        array $guestData,
    ): Reservation {
        $reservation = DB::transaction(function () use ($intent, $guestData): Reservation {
            $lockedIntent = FacilityRequirementIntent::query()
                ->with('groups.facilityProduct.productRates')
                ->whereKey($intent->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedIntent->status !== 'Draft' || $lockedIntent->reservation_id !== null) {
                throw new InvalidArgumentException('This facility plan has already been submitted.');
            }

            if ($lockedIntent->expires_at->isPast() || $lockedIntent->groups->isEmpty()) {
                throw new InvalidArgumentException('This facility plan has expired or has no requirements.');
            }

            $facilities = $this->lockCandidateFacilities($lockedIntent);
            $assignments = $this->allocate($lockedIntent, $facilities);
            $roomOccupantGroups = array_values($guestData['room_occupants'] ?? []);
            $roomAssignmentCount = collect($assignments)
                ->filter(fn (array $assignment): bool => $this->isRoomAssignment($assignment))
                ->count();
            $expectedExtraGuests = collect($assignments)->sum(
                fn (array $assignment): int => $assignment['paid_extra_guest_count'],
            );

            if (count($roomOccupantGroups) !== $roomAssignmentCount) {
                throw new InvalidArgumentException(
                    "The selected rooms require occupant lists for exactly {$roomAssignmentCount} room(s).",
                );
            }

            $address = Address::query()->firstOrCreate([
                'purok' => filled($guestData['purok'] ?? null) ? trim((string) $guestData['purok']) : null,
                'province' => trim((string) ($guestData['province'] ?? '')),
                'city' => trim((string) ($guestData['city'] ?? '')),
                'barangay' => filled($guestData['barangay'] ?? null) ? trim((string) $guestData['barangay']) : null,
            ]);
            $guest = Guest::query()->create([
                'first_name' => trim((string) ($guestData['first_name'] ?? '')),
                'middle_name' => filled($guestData['middle_name'] ?? null) ? trim((string) $guestData['middle_name']) : null,
                'last_name' => trim((string) ($guestData['last_name'] ?? '')),
                'contact_no' => trim((string) ($guestData['contact_no'] ?? '')),
                'email' => trim((string) ($guestData['email'] ?? '')),
                'address_id' => $address->address_id,
            ]);
            $totalPrice = $this->money->add(...collect($assignments)->pluck('quote.total_price')->all());
            $paymentAmount = null;
            $paymentMode = null;
            $paymentReference = null;
            $proofPath = null;

            if (array_key_exists('payment_amount', $guestData)) {
                $paymentAmount = $this->money->normalize((string) $guestData['payment_amount']);
                $minimumPayment = $this->money->percentage($totalPrice, '0.500000');

                if ($this->money->compare($paymentAmount, $minimumPayment) === -1) {
                    throw new InvalidArgumentException(
                        'Reservation downpayment must be at least 50% of the final discounted total.',
                    );
                }

                if ($this->money->compare($paymentAmount, $totalPrice) === 1) {
                    throw new InvalidArgumentException(
                        'Reservation downpayment cannot exceed the reservation total.',
                    );
                }

                $paymentMode = ModeOfPayment::query()
                    ->whereRaw('LOWER(mode_of_payment) = ?', ['gcash'])
                    ->first();

                if ($paymentMode === null) {
                    throw new InvalidArgumentException('GCash payment mode is not configured.');
                }

                $paymentReference = $this->gcashReferences->assertAvailable(
                    trim((string) ($guestData['reference_number'] ?? '')),
                );
                $proofPath = trim((string) ($guestData['proof_of_payment_path'] ?? ''));

                if ($proofPath === '') {
                    throw new InvalidArgumentException('Proof of payment is required.');
                }
            }

            $reservation = Reservation::query()->create([
                'r_ref_no' => $this->newReference(),
                'guest_id' => $guest->guest_id,
                'reservation_date' => today()->toDateString(),
                'total_price' => $totalPrice,
                'amount_due' => $totalPrice,
                'no_of_extra_guests' => $expectedExtraGuests,
                'total_guest_count' => $lockedIntent->party_count,
                'user_id' => filled($guestData['user_id'] ?? null) ? (int) $guestData['user_id'] : null,
                'status' => 'Active',
            ]);

            $roomIndex = 0;

            foreach ($assignments as $assignment) {
                $group = $assignment['group'];
                $quote = $assignment['quote'];
                $detail = ReservationDetail::query()->create([
                    'reservation_id' => $reservation->reservation_id,
                    'facility_id' => $assignment['facility']->facility_id,
                    'rate_type' => $quote['rate_type'],
                    'check_in_date' => $group->check_in_date->toDateString(),
                    'check_out_date' => $group->check_out_date->toDateString(),
                    'discount_id' => $quote['discount_id'],
                    ...$quote['detail_snapshot'],
                ]);

                $this->scheduleBlocks->acquireForReservationDetail($detail);

                if ($this->isRoomAssignment($assignment)) {
                    $occupants = $this->roomOccupants->createForReservation(
                        $detail,
                        $roomOccupantGroups[$roomIndex] ?? [],
                        $assignment['guest_count'],
                    );
                    $included = (int) $assignment['group']->facilityProduct->included_guest_count;
                    $this->detailGuests->createForReservation(
                        $reservation,
                        $detail,
                        array_slice($occupants, $included),
                    );
                    $roomIndex++;
                }
            }

            if ($paymentAmount !== null && $paymentMode !== null) {
                Payment::query()->create([
                    'p_ref_no' => $this->newPaymentReference(),
                    'booking_id' => null,
                    'reservation_id' => $reservation->reservation_id,
                    'entrance_slip_id' => null,
                    'mode_of_payment_id' => $paymentMode->mode_of_payment_id,
                    'reference_number' => $paymentReference,
                    'proof_of_payment_path' => $proofPath,
                    'amount_paid' => $paymentAmount,
                    'date_paid' => today()->toDateString(),
                    'user_id' => null,
                    'payment_status' => 'Pending',
                    'verified_by_user_id' => null,
                    'verified_at' => null,
                ]);
            }

            $lockedIntent->update([
                'status' => 'Fulfilled',
                'reservation_id' => $reservation->reservation_id,
                'fulfilled_at' => now(),
            ]);

            return $reservation->fresh([
                'guest.address',
                'details.facility.facilityType',
                'details.discount',
                'extraGuests',
                'payments.modeOfPayment',
                'entranceSlip.details.entranceFee',
            ]);
        }, attempts: 3);

        $this->confirmationEmail->sendReservationCreated($reservation);

        return $reservation;
    }

    /** @return numeric-string */
    public function quoteIntentTotal(FacilityRequirementIntent $intent): string
    {
        return DB::transaction(function () use ($intent): string {
            $lockedIntent = FacilityRequirementIntent::query()
                ->with('groups.facilityProduct.productRates')
                ->whereKey($intent->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedIntent->status !== 'Draft' || $lockedIntent->expires_at->isPast()) {
                throw new InvalidArgumentException('This facility plan can no longer be quoted.');
            }

            $assignments = $this->allocate(
                $lockedIntent,
                $this->lockCandidateFacilities($lockedIntent),
            );

            return $this->money->add(
                ...collect($assignments)->pluck('quote.total_price')->all(),
            );
        }, attempts: 3);
    }

    /** @param array<string, mixed> $guestData */
    public function createPendingBooking(
        FacilityRequirementIntent $intent,
        array $guestData,
    ): Booking {
        $booking = DB::transaction(function () use ($intent, $guestData): Booking {
            $lockedIntent = FacilityRequirementIntent::query()
                ->with('groups.facilityProduct.productRates')
                ->whereKey($intent->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedIntent->status !== 'Draft'
                || $lockedIntent->reservation_id !== null
                || $lockedIntent->booking_id !== null
            ) {
                throw new InvalidArgumentException('This facility plan has already been submitted.');
            }

            if ($lockedIntent->expires_at->isPast() || $lockedIntent->groups->isEmpty()) {
                throw new InvalidArgumentException('This facility plan has expired or has no requirements.');
            }

            $facilities = $this->lockCandidateFacilities($lockedIntent);
            $assignments = $this->allocate($lockedIntent, $facilities);
            $roomOccupantGroups = array_values($guestData['room_occupants'] ?? []);
            $roomAssignmentCount = collect($assignments)
                ->filter(fn (array $assignment): bool => $this->isRoomAssignment($assignment))
                ->count();
            $expectedExtraGuests = collect($assignments)->sum(
                fn (array $assignment): int => $assignment['paid_extra_guest_count'],
            );

            if (count($roomOccupantGroups) !== $roomAssignmentCount) {
                throw new InvalidArgumentException(
                    "The selected rooms require occupant lists for exactly {$roomAssignmentCount} room(s).",
                );
            }

            $totalPrice = $this->money->add(...collect($assignments)->pluck('quote.total_price')->all());
            $paymentAmount = $this->money->normalize((string) ($guestData['payment_amount'] ?? '0.00'));

            if (! $this->money->equals($paymentAmount, $totalPrice)) {
                throw new InvalidArgumentException(
                    'Direct online booking requires exact full GCash payment before submission. Staff will still verify the proof.',
                );
            }

            $mode = ModeOfPayment::query()
                ->whereRaw('LOWER(mode_of_payment) = ?', ['gcash'])
                ->first();

            if ($mode === null) {
                throw new InvalidArgumentException('GCash payment mode is not configured.');
            }

            $referenceNumber = $this->gcashReferences->assertAvailable(
                trim((string) ($guestData['reference_number'] ?? '')),
            );
            $proofPath = trim((string) ($guestData['proof_of_payment_path'] ?? ''));

            if ($proofPath === '') {
                throw new InvalidArgumentException('Proof of payment is required.');
            }

            $address = Address::query()->firstOrCreate([
                'purok' => filled($guestData['purok'] ?? null) ? trim((string) $guestData['purok']) : null,
                'province' => trim((string) ($guestData['province'] ?? '')),
                'city' => trim((string) ($guestData['city'] ?? '')),
                'barangay' => filled($guestData['barangay'] ?? null) ? trim((string) $guestData['barangay']) : null,
            ]);
            $guest = Guest::query()->create([
                'first_name' => trim((string) ($guestData['first_name'] ?? '')),
                'middle_name' => filled($guestData['middle_name'] ?? null) ? trim((string) $guestData['middle_name']) : null,
                'last_name' => trim((string) ($guestData['last_name'] ?? '')),
                'contact_no' => trim((string) ($guestData['contact_no'] ?? '')),
                'email' => trim((string) ($guestData['email'] ?? '')),
                'address_id' => $address->address_id,
            ]);

            $booking = Booking::query()->create([
                'b_ref_no' => $this->newBookingReference(),
                'guest_id' => $guest->guest_id,
                'booking_date' => today()->toDateString(),
                'no_of_extra_guests' => $expectedExtraGuests,
                'total_guest_count' => $lockedIntent->party_count,
                'total_price' => $totalPrice,
                'amount_due' => $totalPrice,
                'user_id' => null,
                'reservation_id' => null,
                'entrance_slip_id' => null,
                'status' => 'Pending Verification',
            ]);

            $roomIndex = 0;

            foreach ($assignments as $assignment) {
                $group = $assignment['group'];
                $quote = $assignment['quote'];
                $detail = BookingDetail::query()->create([
                    'booking_id' => $booking->booking_id,
                    'facility_id' => $assignment['facility']->facility_id,
                    'rate_type' => $quote['rate_type'],
                    'check_in_date' => $group->check_in_date->toDateString(),
                    'check_out_date' => $group->check_out_date->toDateString(),
                    'check_in_time' => null,
                    'status' => 'Pending Verification',
                    'discount_id' => $quote['discount_id'],
                    'user_id' => null,
                    ...$quote['detail_snapshot'],
                ]);

                $this->scheduleBlocks->acquireForBookingDetail($detail);

                if ($this->isRoomAssignment($assignment)) {
                    $occupants = $this->roomOccupants->createForBooking(
                        $detail,
                        $roomOccupantGroups[$roomIndex] ?? [],
                        $assignment['guest_count'],
                    );
                    $included = (int) $assignment['group']->facilityProduct->included_guest_count;
                    $this->detailGuests->createForBooking(
                        $booking,
                        $detail,
                        array_slice($occupants, $included),
                    );
                    $roomIndex++;
                }
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
                'date_paid' => today()->toDateString(),
                'user_id' => null,
                'payment_status' => 'Pending',
                'verified_by_user_id' => null,
                'verified_at' => null,
            ]);

            $lockedIntent->update([
                'status' => 'Fulfilled',
                'booking_id' => $booking->booking_id,
                'fulfilled_at' => now(),
            ]);

            return $booking->fresh([
                'guest.address',
                'details.facility.facilityType',
                'details.discount',
                'extraGuests',
                'payments.modeOfPayment',
            ]);
        }, attempts: 3);

        $this->confirmationEmail->sendBookingSubmitted($booking);

        return $booking;
    }

    /** @param array<string, mixed> $guestData */
    public function createStaffBooking(
        FacilityRequirementIntent $intent,
        array $guestData,
    ): Booking {
        return DB::transaction(function () use ($intent, $guestData): Booking {
            $userId = (int) ($guestData['user_id'] ?? 0);

            if ($userId < 1) {
                throw new InvalidArgumentException('A staff user is required to create a booking.');
            }

            $lockedIntent = FacilityRequirementIntent::query()
                ->with('groups.facilityProduct.productRates')
                ->whereKey($intent->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedIntent->status !== 'Draft'
                || $lockedIntent->reservation_id !== null
                || $lockedIntent->booking_id !== null
            ) {
                throw new InvalidArgumentException('This facility plan has already been submitted.');
            }

            if ($lockedIntent->expires_at->isPast() || $lockedIntent->groups->isEmpty()) {
                throw new InvalidArgumentException('This facility plan has expired or has no requirements.');
            }

            $assignments = $this->allocate(
                $lockedIntent,
                $this->lockCandidateFacilities($lockedIntent),
            );
            $roomOccupantGroups = array_values($guestData['room_occupants'] ?? []);
            $roomAssignmentCount = collect($assignments)
                ->filter(fn (array $assignment): bool => $this->isRoomAssignment($assignment))
                ->count();

            if (count($roomOccupantGroups) !== $roomAssignmentCount) {
                throw new InvalidArgumentException(
                    "The selected rooms require occupant lists for exactly {$roomAssignmentCount} room(s).",
                );
            }

            $expectedExtraGuests = collect($assignments)->sum(
                fn (array $assignment): int => $assignment['paid_extra_guest_count'],
            );
            $facilityTotal = $this->money->add(...collect($assignments)->pluck('quote.total_price')->all());
            $walkIn = (bool) ($guestData['walk_in'] ?? false);
            $admissionTotal = '0.00';

            if ($walkIn) {
                $entranceData = $guestData['entrance'] ?? [];
                $entranceGuestCount = (int) ($entranceData['adult_count'] ?? 0)
                    + (int) ($entranceData['children_count'] ?? 0)
                    + (int) ($entranceData['pwd_sc_count'] ?? 0);

                if ($entranceGuestCount !== (int) $lockedIntent->party_count) {
                    throw new InvalidArgumentException(
                        'Walk-In entrance categories must equal the booking party count.',
                    );
                }

                $admissionQuote = $this->entranceSlips->quote($entranceData);
                $admissionTotal = $this->money->normalize((string) $admissionQuote['amount_due']);
            }

            $coreTotal = $this->money->add($facilityTotal, $admissionTotal);
            $paymentAmount = $this->money->normalize((string) ($guestData['payment_amount'] ?? '0.00'));

            if (! $this->money->equals($paymentAmount, $coreTotal)) {
                throw new InvalidArgumentException(
                    'A staff-created booking requires full payment of all core facility and admission charges.',
                );
            }

            $mode = ModeOfPayment::query()->findOrFail((int) ($guestData['mode_of_payment_id'] ?? 0));
            $referenceNumber = trim((string) ($guestData['reference_number'] ?? ''));

            if (strtolower(trim((string) $mode->mode_of_payment)) === 'gcash') {
                $referenceNumber = $this->gcashReferences->assertAvailable($referenceNumber);
            } elseif ($referenceNumber === '') {
                $referenceNumber = null;
            }

            $address = Address::query()->firstOrCreate([
                'purok' => filled($guestData['purok'] ?? null) ? trim((string) $guestData['purok']) : null,
                'province' => trim((string) ($guestData['province'] ?? '')),
                'city' => trim((string) ($guestData['city'] ?? '')),
                'barangay' => filled($guestData['barangay'] ?? null) ? trim((string) $guestData['barangay']) : null,
            ]);
            $guest = Guest::query()->create([
                'first_name' => trim((string) ($guestData['first_name'] ?? '')),
                'middle_name' => filled($guestData['middle_name'] ?? null) ? trim((string) $guestData['middle_name']) : null,
                'last_name' => trim((string) ($guestData['last_name'] ?? '')),
                'contact_no' => trim((string) ($guestData['contact_no'] ?? '')),
                'email' => trim((string) ($guestData['email'] ?? '')),
                'address_id' => $address->address_id,
            ]);

            $status = $walkIn ? 'Checked-in' : 'Booked';

            $booking = Booking::query()->create([
                'b_ref_no' => $this->newBookingReference(),
                'guest_id' => $guest->guest_id,
                'booking_date' => today()->toDateString(),
                'no_of_extra_guests' => $expectedExtraGuests,
                'total_guest_count' => $lockedIntent->party_count,
                'total_price' => $facilityTotal,
                'amount_due' => '0.00',
                'user_id' => $userId,
                'reservation_id' => null,
                'entrance_slip_id' => null,
                'status' => $status,
            ]);

            $roomIndex = 0;

            foreach ($assignments as $assignment) {
                $group = $assignment['group'];
                $quote = $assignment['quote'];
                $detail = BookingDetail::query()->create([
                    'booking_id' => $booking->booking_id,
                    'facility_id' => $assignment['facility']->facility_id,
                    'rate_type' => $quote['rate_type'],
                    'check_in_date' => $group->check_in_date->toDateString(),
                    'check_out_date' => $group->check_out_date->toDateString(),
                    'check_in_time' => null,
                    'status' => $status,
                    'discount_id' => $quote['discount_id'],
                    'user_id' => $userId,
                    ...$quote['detail_snapshot'],
                ]);

                $this->scheduleBlocks->acquireForBookingDetail($detail);

                if ($this->isRoomAssignment($assignment)) {
                    $occupants = $this->roomOccupants->createForBooking(
                        $detail,
                        $roomOccupantGroups[$roomIndex] ?? [],
                        $assignment['guest_count'],
                    );
                    $included = (int) $assignment['group']->facilityProduct->included_guest_count;
                    $this->detailGuests->createForBooking(
                        $booking,
                        $detail,
                        array_slice($occupants, $included),
                    );
                    $roomIndex++;
                }
            }

            if ($walkIn) {
                $entranceData = $guestData['entrance'] ?? [];
                $slip = $this->entranceSlips->issueSettledWalkIn([
                    ...$entranceData,
                    'user_id' => $userId,
                    'guest_id' => $guest->guest_id,
                ]);

                $booking->update([
                    'entrance_slip_id' => $slip->entrance_slip_id,
                    'total_price' => $coreTotal,
                ]);

                TransactionAdjustment::query()->create([
                    'reservation_id' => null,
                    'booking_id' => $booking->booking_id,
                    'entrance_slip_id' => null,
                    'direction' => 'Debit',
                    'amount' => $admissionTotal,
                    'reason' => 'Walk-In entrance/admission charges settled with the booking core payment.',
                    'created_by_user_id' => $userId,
                ]);
            }

            Payment::query()->create([
                'p_ref_no' => $this->newPaymentReference(),
                'booking_id' => $booking->booking_id,
                'reservation_id' => null,
                'entrance_slip_id' => null,
                'mode_of_payment_id' => $mode->mode_of_payment_id,
                'reference_number' => $referenceNumber,
                'proof_of_payment_path' => null,
                'amount_paid' => $paymentAmount,
                'date_paid' => today()->toDateString(),
                'user_id' => $userId,
                'payment_status' => 'Verified',
                'verified_by_user_id' => $userId,
                'verified_at' => now(),
            ]);

            if ($walkIn && ($guestData['amenities'] ?? []) !== []) {
                $this->addWalkInAmenityBalance(
                    $booking,
                    $guestData['amenities'],
                    $userId,
                );
            }

            $lockedIntent->update([
                'status' => 'Fulfilled',
                'booking_id' => $booking->booking_id,
                'fulfilled_at' => now(),
            ]);

            return $booking->fresh([
                'guest.address',
                'details.facility.facilityType',
                'details.discount',
                'details.roomOccupants',
                'extraGuests',
                'payments.modeOfPayment',
            ]);
        }, attempts: 3);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function addWalkInAmenityBalance(
        Booking $booking,
        array $items,
        int $userId,
    ): void {
        $deliveryFacilityId = (int) $booking->details()
            ->orderBy('booking_details_id')
            ->value('facility_id');

        if ($deliveryFacilityId < 1) {
            throw new InvalidArgumentException(
                'Walk-In amenities require an assigned booking facility.',
            );
        }

        $quoted = [];
        $total = '0.00';

        foreach ($items as $item) {
            $amenityId = (int) ($item['amenity_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($amenityId < 1 || $quantity < 1) {
                continue;
            }

            $amenity = Amenity::query()
                ->with('amenityName')
                ->lockForUpdate()
                ->findOrFail($amenityId);

            if (strtolower((string) $amenity->amenity_type) !== 'rentable') {
                throw new InvalidArgumentException(
                    'Only rentable amenities can be added to a Walk-In booking.',
                );
            }

            $unitPrice = $this->money->normalize((string) $amenity->amenity_price);

            if ($this->money->compare($unitPrice, '0.00') !== 1) {
                throw new InvalidArgumentException(
                    'Rentable amenities must have a price greater than zero.',
                );
            }

            $lineTotal = $this->money->multiply($unitPrice, $quantity);
            $total = $this->money->add($total, $lineTotal);
            $quoted[] = [
                'amenity_id' => $amenity->amenity_id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        if ($quoted === []) {
            return;
        }

        $request = AmenityRequest::query()->create([
            'booking_id' => $booking->booking_id,
            'amenity_request_status' => 'Pending',
            'total_price' => $total,
            'date_created' => today()->toDateString(),
            'user_id' => $userId,
            'assigned_to_user_id' => null,
            'delivered_at' => null,
            'cancelled_at' => null,
        ]);

        foreach ($quoted as $item) {
            $request->details()->create([
                'facility_id' => $deliveryFacilityId,
                'amenity_id' => $item['amenity_id'],
                'amenity_quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
            ]);
        }

        $booking->update([
            'total_price' => $this->money->add((string) $booking->total_price, $total),
            'amount_due' => $total,
        ]);
    }

    /** @return EloquentCollection<int, Facility> */
    private function lockCandidateFacilities(FacilityRequirementIntent $intent): EloquentCollection
    {
        $productIds = $intent->groups
            ->pluck('facility_product_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        return Facility::query()
            ->with(['facilityType', 'facilityProduct.productRates'])
            ->whereIn('facility_product_id', $productIds->all())
            ->where('facility_status', 'Available')
            ->orderBy('facility_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  EloquentCollection<int, Facility>  $facilities
     * @return array<int, array{group: FacilityRequirementGroup, facility: Facility, quote: array<string, mixed>, guest_count: int, paid_extra_guest_count: int}>
     */
    private function allocate(FacilityRequirementIntent $intent, EloquentCollection $facilities): array
    {
        $candidateIds = $facilities->modelKeys();
        $occupiedKeys = FacilityScheduleBlock::query()
            ->whereIn('facility_id', $candidateIds)
            ->lockForUpdate()
            ->get()
            ->mapWithKeys(fn (FacilityScheduleBlock $block): array => [
                $this->blockKey(
                    (int) $block->facility_id,
                    $block->service_date->toDateString(),
                    $block->slot->value,
                ) => true,
            ]);
        $plannedKeys = [];
        $assignments = [];

        foreach ($intent->groups->sortBy('facility_requirement_group_id') as $group) {
            $groupFacilities = $facilities
                ->where('facility_product_id', $group->facility_product_id)
                ->values();
            $guestCounts = $this->distributedGuestCounts($group);
            $assigned = 0;

            foreach ($groupFacilities as $facility) {
                $blocks = $this->scheduleBlocks->requiredBlocks(
                    (int) $facility->facility_id,
                    $group->facilityProduct->schedule_policy,
                    $group->rate_code,
                    $group->check_in_date,
                    $group->check_out_date,
                );
                $keys = collect($blocks)->map(fn (array $block): string => $this->blockKey(
                    $block['facility_id'],
                    $block['service_date'],
                    $block['slot']->value,
                ));

                if ($keys->contains(fn (string $key): bool => $occupiedKeys->has($key) || isset($plannedKeys[$key]))) {
                    continue;
                }

                $guestCount = $guestCounts[$assigned];
                $discount = $this->discounts->resolveForFacility(
                    (int) $facility->facility_id,
                    $group->check_in_date->toDateString(),
                );
                $rateType = $this->products->canonicalRateType($group->rate_code);
                $quote = $this->quotes->quote(
                    facilityId: (int) $facility->facility_id,
                    rateType: $rateType,
                    checkInDate: $group->check_in_date->toDateString(),
                    checkOutDate: $group->check_out_date->toDateString(),
                    discountId: $discount?->discount_id,
                    totalGuestCount: $guestCount,
                );

                foreach ($keys as $key) {
                    $plannedKeys[$key] = true;
                }

                $assignments[] = [
                    'group' => $group,
                    'facility' => $facility,
                    'quote' => $quote,
                    'guest_count' => $guestCount,
                    'paid_extra_guest_count' => (int) $quote['extra_guest_count'],
                ];
                $assigned++;

                if ($assigned === $group->quantity) {
                    break;
                }
            }

            if ($assigned !== $group->quantity) {
                throw new InvalidArgumentException('The requested facility quantity is no longer available. Please refresh the plan and try again.');
            }
        }

        return $assignments;
    }

    /** @return array<int, int> */
    private function distributedGuestCounts(FacilityRequirementGroup $group): array
    {
        $minimum = intdiv($group->estimated_users, $group->quantity);
        $remainder = $group->estimated_users % $group->quantity;

        return array_map(
            fn (int $index): int => $minimum + ($index < $remainder ? 1 : 0),
            range(0, $group->quantity - 1),
        );
    }

    /** @param array<string, mixed> $assignment */
    private function isRoomAssignment(array $assignment): bool
    {
        return $assignment['group']->facilityProduct->included_guest_count !== null
            && $assignment['group']->facilityProduct->strict_maximum !== null;
    }

    private function blockKey(int $facilityId, string $date, string $slot): string
    {
        return $facilityId.'|'.$date.'|'.$slot;
    }

    private function newReference(): string
    {
        do {
            $reference = 'R'.now()->format('ymdHis').Str::upper(Str::random(4));
        } while (Reservation::query()->where('r_ref_no', $reference)->exists());

        return $reference;
    }

    private function newBookingReference(): string
    {
        do {
            $reference = 'B'.now()->format('ymdHis').Str::upper(Str::random(4));
        } while (Booking::query()->where('b_ref_no', $reference)->exists());

        return $reference;
    }

    private function newPaymentReference(): string
    {
        do {
            $reference = 'P'.now()->format('ymdHis').Str::upper(Str::random(4));
        } while (Payment::query()->where('p_ref_no', $reference)->exists());

        return $reference;
    }
}

<?php

namespace App\Services;

use App\Models\Address;
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

            $reservation = Reservation::query()->create([
                'r_ref_no' => $this->newReference(),
                'guest_id' => $guest->guest_id,
                'reservation_date' => today()->toDateString(),
                'total_price' => $totalPrice,
                'amount_due' => $totalPrice,
                'no_of_extra_guests' => $expectedExtraGuests,
                'total_guest_count' => $lockedIntent->party_count,
                'user_id' => null,
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

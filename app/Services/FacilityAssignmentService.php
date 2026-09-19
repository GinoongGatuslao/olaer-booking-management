<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Facility;
use App\Models\FacilityRequirementGroup;
use App\Models\FacilityRequirementIntent;
use App\Models\FacilityScheduleBlock;
use App\Models\Guest;
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
        private readonly DecimalMoneyService $money,
        private readonly GuestConfirmationEmailService $confirmationEmail,
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
            $extraGuests = $this->cleanExtraGuests($guestData['extra_guests'] ?? []);
            $expectedExtraGuests = collect($assignments)->sum(
                fn (array $assignment): int => $assignment['paid_extra_guest_count'],
            );

            if (count($extraGuests) !== $expectedExtraGuests) {
                throw new InvalidArgumentException(
                    "The selected rooms require exactly {$expectedExtraGuests} paid extra guest name(s).",
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

            $extraGuestOffset = 0;

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

                $detailExtraGuests = array_slice(
                    $extraGuests,
                    $extraGuestOffset,
                    $assignment['paid_extra_guest_count'],
                );
                $this->detailGuests->createForReservation($reservation, $detail, $detailExtraGuests);
                $extraGuestOffset += $assignment['paid_extra_guest_count'];
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
     * @return array<int, array{group: FacilityRequirementGroup, facility: Facility, quote: array<string, mixed>, paid_extra_guest_count: int}>
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

    /**
     * @param  array<int, array<string, mixed>>  $extraGuests
     * @return array<int, array{first_name: string, middle_name: string|null, last_name: string}>
     */
    private function cleanExtraGuests(array $extraGuests): array
    {
        return collect($extraGuests)->map(fn (array $guest): array => [
            'first_name' => trim((string) ($guest['first_name'] ?? '')),
            'middle_name' => filled($guest['middle_name'] ?? null) ? trim((string) $guest['middle_name']) : null,
            'last_name' => trim((string) ($guest['last_name'] ?? '')),
        ])->all();
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
}

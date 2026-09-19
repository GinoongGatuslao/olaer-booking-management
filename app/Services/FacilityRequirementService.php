<?php

namespace App\Services;

use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\FacilityProduct;
use App\Models\FacilityRequirementIntent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FacilityRequirementService
{
    public function __construct(
        private readonly FacilityAvailabilityService $availability,
        private readonly FacilityScheduleBlockService $scheduleBlocks,
    ) {}

    public function createIntent(string $sessionId, int $partyCount = 1): FacilityRequirementIntent
    {
        if ($sessionId === '' || $partyCount < 1) {
            throw new InvalidArgumentException('A valid session and party count are required.');
        }

        return FacilityRequirementIntent::query()->create([
            'token' => (string) Str::uuid(),
            'session_id' => $sessionId,
            'party_count' => $partyCount,
            'status' => 'Draft',
            'expires_at' => now()->addHours(2),
        ]);
    }

    public function ownedIntent(string $token, string $sessionId): FacilityRequirementIntent
    {
        $intent = FacilityRequirementIntent::query()
            ->with(['groups.facilityProduct.productRates', 'reservation'])
            ->where('token', $token)
            ->where('session_id', $sessionId)
            ->where('expires_at', '>', now())
            ->first();

        if (! $intent) {
            throw new InvalidArgumentException('This facility plan has expired or does not belong to this session.');
        }

        return $intent;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    public function replaceGroups(
        FacilityRequirementIntent $intent,
        int $partyCount,
        array $groups,
    ): FacilityRequirementIntent {
        if ($partyCount < 1 || $groups === []) {
            throw new InvalidArgumentException('Enter a party count and at least one facility requirement.');
        }

        return DB::transaction(function () use ($intent, $partyCount, $groups): FacilityRequirementIntent {
            $lockedIntent = FacilityRequirementIntent::query()
                ->whereKey($intent->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedIntent->status !== 'Draft' || $lockedIntent->expires_at->isPast()) {
                throw new InvalidArgumentException('This facility plan can no longer be changed.');
            }

            $normalizedGroups = $this->normalizedGroups($groups);

            if (collect($normalizedGroups)->contains(
                fn (array $group): bool => $group['estimated_users'] > $partyCount,
            )) {
                throw new InvalidArgumentException('A facility guest estimate cannot exceed the total party count.');
            }

            $this->assertAggregateAvailability($normalizedGroups);

            $lockedIntent->update(['party_count' => $partyCount]);
            $lockedIntent->groups()->delete();

            foreach ($normalizedGroups as $group) {
                $lockedIntent->groups()->create($group);
            }

            return $lockedIntent->fresh(['groups.facilityProduct.productRates']);
        });
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array{available: int, requested: int, remaining: int}
     */
    public function availabilityFor(array $group): array
    {
        $normalized = $this->normalizeGroup($group);
        $available = $this->availability->availabilityCount(
            $normalized['facility_product_id'],
            $normalized['rate_code'],
            $normalized['check_in_date'],
            $normalized['check_out_date'],
        );

        return [
            'available' => $available,
            'requested' => $normalized['quantity'],
            'remaining' => max(0, $available - $normalized['quantity']),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array{facility_product_id: int, quantity: int, rate_code: string, check_in_date: string, check_out_date: string, estimated_users: int}>
     */
    private function normalizedGroups(array $groups): array
    {
        return array_map(fn (array $group): array => $this->normalizeGroup($group), $groups);
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array{facility_product_id: int, quantity: int, rate_code: string, check_in_date: string, check_out_date: string, estimated_users: int}
     */
    private function normalizeGroup(array $group): array
    {
        $productId = (int) ($group['facility_product_id'] ?? 0);
        $quantity = (int) ($group['quantity'] ?? 0);
        $estimatedUsers = (int) ($group['estimated_users'] ?? 0);
        $rateCode = FacilityRateCode::tryFrom((string) ($group['rate_code'] ?? ''));
        $checkInDate = (string) ($group['check_in_date'] ?? '');
        $checkOutDate = (string) ($group['check_out_date'] ?? '');

        if ($productId < 1 || $quantity < 1 || $quantity > 50 || $estimatedUsers < $quantity || $rateCode === null || $checkInDate === '' || $checkOutDate === '') {
            throw new InvalidArgumentException('Each facility requirement needs a valid product, rate, quantity, and guest estimate.');
        }

        $checkIn = CarbonImmutable::parse($checkInDate)->startOfDay();
        $checkOut = CarbonImmutable::parse($checkOutDate)->startOfDay();

        if ($checkIn->isBefore(today()) || $checkOut->lessThan($checkIn)) {
            throw new InvalidArgumentException('Facility requirement dates are invalid.');
        }

        $product = FacilityProduct::query()->with('productRates')->findOrFail($productId);

        if ($product->schedule_policy === FacilitySchedulePolicy::Overnight) {
            if (! $checkOut->greaterThan($checkIn)) {
                throw new InvalidArgumentException('Room check-out must be after check-in.');
            }
        } elseif (! $checkOut->equalTo($checkIn)) {
            throw new InvalidArgumentException('Cottages and function halls must use the same start and end date.');
        }

        $this->availability->availabilityCount(
            $productId,
            $rateCode,
            $checkIn->toDateString(),
            $checkOut->toDateString(),
        );

        if ($product->strict_maximum !== null && $estimatedUsers > $quantity * $product->strict_maximum) {
            throw new InvalidArgumentException('The room guest estimate exceeds the selected quantity\'s maximum capacity.');
        }

        return [
            'facility_product_id' => $productId,
            'quantity' => $quantity,
            'rate_code' => $rateCode->value,
            'check_in_date' => $checkIn->toDateString(),
            'check_out_date' => $checkOut->toDateString(),
            'estimated_users' => $estimatedUsers,
        ];
    }

    /**
     * @param  array<int, array{facility_product_id: int, quantity: int, rate_code: string, check_in_date: string, check_out_date: string, estimated_users: int}>  $groups
     */
    private function assertAggregateAvailability(array $groups): void
    {
        $plannedKeys = [];

        foreach ($groups as $group) {
            $requiredKeys = $this->scheduleKeys($group);
            $candidateIds = $this->availability->availableFacilityIds(
                $group['facility_product_id'],
                $group['rate_code'],
                $group['check_in_date'],
                $group['check_out_date'],
            );
            $assigned = 0;

            foreach ($candidateIds as $facilityId) {
                $facilityKeys = array_map(
                    fn (string $scheduleKey): string => $facilityId.'|'.$scheduleKey,
                    $requiredKeys,
                );

                if (array_intersect_key($plannedKeys, array_flip($facilityKeys)) !== []) {
                    continue;
                }

                foreach ($facilityKeys as $facilityKey) {
                    $plannedKeys[$facilityKey] = true;
                }

                $assigned++;

                if ($assigned === $group['quantity']) {
                    break;
                }
            }

            if ($assigned !== $group['quantity']) {
                throw new InvalidArgumentException('The requested facility quantity is no longer available for the selected schedule.');
            }
        }
    }

    /**
     * @param  array{facility_product_id: int, quantity: int, rate_code: string, check_in_date: string, check_out_date: string, estimated_users: int}  $group
     * @return array<int, string>
     */
    private function scheduleKeys(array $group): array
    {
        $product = FacilityProduct::query()
            ->whereKey($group['facility_product_id'])
            ->firstOrFail();

        return collect($this->scheduleBlocks->requiredBlocks(
            1,
            $product->schedule_policy,
            $group['rate_code'],
            $group['check_in_date'],
            $group['check_out_date'],
        ))->map(fn (array $block): string => $block['service_date'].'|'.$block['slot']->value)->all();
    }
}

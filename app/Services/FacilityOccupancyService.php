<?php

namespace App\Services;

use App\Models\Facility;
use App\Models\FacilityProduct;
use InvalidArgumentException;

class FacilityOccupancyService
{
    public const ROOM_INCLUDED_GUESTS = 4;

    public function __construct(
        private readonly FacilityProductConfigurationService $products,
    ) {}

    public function forFacilityId(
        int $facilityId,
        int $totalGuestCount,
    ): array {
        $facility = $this->products->configuredFacility($facilityId);

        return $this->forFacility(
            $facility,
            $totalGuestCount,
        );
    }

    public function forFacility(
        Facility $facility,
        int $detailGuestCount,
        ?int $parentGuestCount = null,
    ): array {
        $product = $this->products->productFor($facility);
        $parentGuestCount ??= $detailGuestCount;

        if ($parentGuestCount < 1) {
            throw new InvalidArgumentException(
                'Total guests must be at least 1, including the primary guest.'
            );
        }

        if ($detailGuestCount < 1) {
            throw new InvalidArgumentException(
                'Facility guest count must be at least 1.',
            );
        }

        if ($detailGuestCount > $parentGuestCount) {
            throw new InvalidArgumentException(
                'A facility guest estimate cannot exceed the transaction\'s total unique guest count.',
            );
        }

        $isRoom = $this->products->isRoomProduct($product);
        $strictMaximum = $product->strict_maximum;

        if (
            $isRoom
            && (
                $strictMaximum === null
                || $strictMaximum < 1
                || $detailGuestCount > $strictMaximum
            )
        ) {
            throw new InvalidArgumentException(
                "{$facility->facility_name} allows a maximum of ".($strictMaximum ?? 0).' guest(s).',
            );
        }

        $includedGuestCount = $isRoom
            ? (int) ($product->included_guest_count ?? 0)
            : null;

        $paidExtraGuestCount = $isRoom
            ? max(0, $detailGuestCount - $includedGuestCount)
            : 0;

        return [
            'facility_id' => (int) $facility->facility_id,
            'facility_name' => (string) $facility->facility_name,
            'facility_type' => $facility->facilityType?->facility_type,
            'facility_product_id' => (int) $product->facility_product_id,
            'capacity_policy' => $product->capacity_policy->value,
            'capacity' => $strictMaximum ?? $product->suggested_maximum,
            'suggested_minimum' => $product->suggested_minimum,
            'suggested_maximum' => $product->suggested_maximum,
            'strict_maximum' => $strictMaximum,
            'total_guest_count' => $parentGuestCount,
            'guest_count' => $detailGuestCount,
            'included_guest_count' => $includedGuestCount,
            'paid_extra_guest_count' => $paidExtraGuestCount,
            'max_paid_extra_guests' => $isRoom
                ? max(0, (int) $strictMaximum - $includedGuestCount)
                : 0,
            'has_paid_extra_guests' => $isRoom,
        ];
    }

    public function maxTotalGuests(?int $facilityId): int
    {
        return $this->strictMaximum($facilityId) ?? PHP_INT_MAX;
    }

    public function strictMaximum(?int $facilityId): ?int
    {
        return $this->products->strictMaximum($facilityId);
    }

    public function maxPaidExtraGuests(?int $facilityId): int
    {
        if (! $facilityId) {
            return 0;
        }

        $facility = Facility::query()
            ->with(['facilityType', 'facilityProduct.productRates'])
            ->find($facilityId);

        if (! $facility) {
            return 0;
        }

        $product = $this->products->productFor($facility);

        return $this->products->isRoomProduct($product)
            ? max(
                0,
                (int) $product->strict_maximum
                    - (int) $product->included_guest_count,
            )
            : 0;
    }

    public function legacyTotalGuestCount(
        Facility $facility,
        int $storedExtraGuestCount,
    ): int {
        $facility->loadMissing(['facilityType', 'facilityProduct']);
        $product = $facility->facilityProduct;

        if ($product instanceof FacilityProduct) {
            $isRoom = $this->products->isRoomProduct($product);
            $inferred = $isRoom
                ? (int) ($product->included_guest_count ?? self::ROOM_INCLUDED_GUESTS)
                    + max(0, $storedExtraGuestCount)
                : 1 + max(0, $storedExtraGuestCount);

            return $isRoom
                ? max(1, min((int) $product->strict_maximum, $inferred))
                : max(1, $inferred);
        }

        $capacity = $this->capacityFor($facility);
        $facilityType = strtolower(
            trim((string) $facility->facilityType?->facility_type)
        );

        $inferred = $facilityType === 'room'
            ? self::ROOM_INCLUDED_GUESTS + max(0, $storedExtraGuestCount)
            : 1 + max(0, $storedExtraGuestCount);

        return max(1, min($capacity, $inferred));
    }

    public function assertNamedPaidExtraGuests(
        array $extraGuests,
        int $expectedPaidExtraGuests,
    ): void {
        $actual = count($extraGuests);

        if ($actual !== $expectedPaidExtraGuests) {
            throw new InvalidArgumentException(
                "The selected total guest count requires exactly {$expectedPaidExtraGuests} paid room extra guest name(s); {$actual} were provided."
            );
        }
    }

    public function capacityFor(Facility $facility): int
    {
        preg_match_all(
            '/\\d+/',
            (string) $facility->capacity,
            $matches,
        );

        $values = array_map(
            'intval',
            $matches[0] ?? [],
        );

        $capacity = $values === []
            ? 0
            : max($values);

        if ($capacity < 1) {
            throw new InvalidArgumentException(
                "{$facility->facility_name} has no valid numeric capacity. Update the facility master data first."
            );
        }

        return $capacity;
    }
}

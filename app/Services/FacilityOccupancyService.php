<?php

namespace App\Services;

use App\Models\Facility;
use InvalidArgumentException;

class FacilityOccupancyService
{
    public const ROOM_INCLUDED_GUESTS = 4;

    public function forFacilityId(
        int $facilityId,
        int $totalGuestCount,
    ): array {
        $facility = Facility::query()
            ->with('facilityType')
            ->findOrFail($facilityId);

        return $this->forFacility(
            $facility,
            $totalGuestCount,
        );
    }

    public function forFacility(
        Facility $facility,
        int $totalGuestCount,
    ): array {
        $capacity = $this->capacityFor($facility);
        $facilityType = strtolower(
            trim((string) $facility->facilityType?->facility_type)
        );

        if ($totalGuestCount < 1) {
            throw new InvalidArgumentException(
                'Total guests must be at least 1, including the primary guest.'
            );
        }

        if ($totalGuestCount > $capacity) {
            throw new InvalidArgumentException(
                "{$facility->facility_name} can accommodate only {$capacity} guest(s)."
            );
        }

        $includedGuestCount = $facilityType === 'room'
            ? min(self::ROOM_INCLUDED_GUESTS, $capacity)
            : $capacity;

        $paidExtraGuestCount = $facilityType === 'room'
            ? max(0, $totalGuestCount - $includedGuestCount)
            : 0;

        return [
            'facility_id' => (int) $facility->facility_id,
            'facility_name' => (string) $facility->facility_name,
            'facility_type' => $facility->facilityType?->facility_type,
            'capacity' => $capacity,
            'total_guest_count' => $totalGuestCount,
            'included_guest_count' => $includedGuestCount,
            'paid_extra_guest_count' => $paidExtraGuestCount,
            'max_paid_extra_guests' => $facilityType === 'room'
                ? max(0, $capacity - $includedGuestCount)
                : 0,
            'has_paid_extra_guests' => $facilityType === 'room',
        ];
    }

    public function maxTotalGuests(?int $facilityId): int
    {
        if (! $facilityId) {
            return 1;
        }

        $facility = Facility::query()
            ->with('facilityType')
            ->find($facilityId);

        return $facility
            ? $this->capacityFor($facility)
            : 1;
    }

    public function maxPaidExtraGuests(?int $facilityId): int
    {
        if (! $facilityId) {
            return 0;
        }

        $facility = Facility::query()
            ->with('facilityType')
            ->find($facilityId);

        if (! $facility) {
            return 0;
        }

        $capacity = $this->capacityFor($facility);
        $facilityType = strtolower(
            trim((string) $facility->facilityType?->facility_type)
        );

        return $facilityType === 'room'
            ? max(0, $capacity - min(self::ROOM_INCLUDED_GUESTS, $capacity))
            : 0;
    }

    public function legacyTotalGuestCount(
        Facility $facility,
        int $storedExtraGuestCount,
    ): int {
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

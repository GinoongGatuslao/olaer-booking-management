<?php

namespace App\Services;

use App\Models\Facility;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class FacilityScheduleLockService
{
    /**
     * Lock one facility schedule row for the current database transaction.
     */
    public function lockOne(int $facilityId): Facility
    {
        return $this->lockMany([$facilityId])->first();
    }

    /**
     * Lock facility rows in deterministic ID order.
     *
     * Every reservation/booking workflow that can occupy a facility should
     * acquire this lock before checking schedule availability and before
     * writing its detail row.
     *
     * @param array<int, int|string> $facilityIds
     * @return Collection<int, Facility>
     */
    public function lockMany(array $facilityIds): Collection
    {
        $ids = collect($facilityIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            throw new InvalidArgumentException(
                'At least one valid facility is required.',
            );
        }

        $facilities = Facility::query()
            ->whereIn('facility_id', $ids->all())
            ->orderBy('facility_id')
            ->lockForUpdate()
            ->get();

        if ($facilities->count() !== $ids->count()) {
            throw new InvalidArgumentException(
                'One or more selected facilities no longer exist.',
            );
        }

        return $facilities;
    }
}

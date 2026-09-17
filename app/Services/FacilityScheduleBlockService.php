<?php

namespace App\Services;

use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\FacilityScheduleSlot;
use App\Models\BookingDetail;
use App\Models\FacilityScheduleBlock;
use App\Models\ReservationDetail;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class FacilityScheduleBlockService
{
    /**
     * @return array<int, array{facility_id: int, service_date: string, slot: FacilityScheduleSlot}>
     */
    public function requiredBlocks(
        int $facilityId,
        FacilitySchedulePolicy|string $schedulePolicy,
        FacilityRateCode|string $rateCode,
        DateTimeInterface|string $checkInDate,
        DateTimeInterface|string $checkOutDate,
    ): array {
        $schedulePolicy = $schedulePolicy instanceof FacilitySchedulePolicy
            ? $schedulePolicy
            : FacilitySchedulePolicy::tryFrom($schedulePolicy);
        $rateCode = $rateCode instanceof FacilityRateCode
            ? $rateCode
            : FacilityRateCode::tryFrom($rateCode);

        if ($facilityId < 1 || $schedulePolicy === null || $rateCode === null) {
            throw new InvalidArgumentException('The facility schedule configuration is invalid.');
        }

        $checkIn = CarbonImmutable::parse($checkInDate)->startOfDay();
        $checkOut = CarbonImmutable::parse($checkOutDate)->startOfDay();

        if ($checkOut->lessThan($checkIn)) {
            throw new InvalidArgumentException('Check-out date cannot be before check-in date.');
        }

        return match ($schedulePolicy) {
            FacilitySchedulePolicy::Overnight => $this->overnightBlocks(
                $facilityId,
                $rateCode,
                $checkIn,
                $checkOut,
            ),
            FacilitySchedulePolicy::DatedSlots => $this->datedSlotBlocks(
                $facilityId,
                $rateCode,
                $checkIn,
                $checkOut,
            ),
            FacilitySchedulePolicy::WholeCalendarDay => $this->wholeDayBlocks(
                $facilityId,
                $rateCode,
                $checkIn,
                $checkOut,
            ),
        };
    }

    public function acquireForReservationDetail(ReservationDetail $detail): void
    {
        $this->synchronize(
            'reservation_detail_id',
            (int) $detail->reservation_details_id,
            $this->requiredBlocksForDetail($detail),
        );
    }

    public function acquireForBookingDetail(BookingDetail $detail): void
    {
        $this->synchronize(
            'booking_detail_id',
            (int) $detail->booking_details_id,
            $this->requiredBlocksForDetail($detail),
        );
    }

    /** @param array<string, mixed> $attributes */
    public function synchronizeReservationDetail(ReservationDetail $detail, array $attributes): void
    {
        $this->synchronize(
            'reservation_detail_id',
            (int) $detail->reservation_details_id,
            $this->requiredBlocksForDetail($detail, $attributes),
        );
    }

    /** @param array<string, mixed> $attributes */
    public function synchronizeBookingDetail(BookingDetail $detail, array $attributes): void
    {
        $this->synchronize(
            'booking_detail_id',
            (int) $detail->booking_details_id,
            $this->requiredBlocksForDetail($detail, $attributes),
        );
    }

    public function extendCottageDayToBoth(BookingDetail $detail): void
    {
        $this->assertInTransaction();

        $currentKeys = $this->keyed($this->requiredBlocksForDetail($detail));
        $dayKey = $currentKeys->sole();

        if ($dayKey['slot'] !== FacilityScheduleSlot::Day) {
            throw new InvalidArgumentException('Only a canonical Day schedule can be extended to Both.');
        }

        $ownedKeys = $this->ownedBlocks('booking_detail_id', (int) $detail->booking_details_id)
            ->toBase()
            ->mapWithKeys(fn (FacilityScheduleBlock $block): array => [
                $this->key(
                    (int) $block->facility_id,
                    $block->service_date->toDateString(),
                    $block->slot,
                ) => true,
            ]);

        if ($ownedKeys->keys()->all() !== $currentKeys->keys()->all()) {
            throw new InvalidArgumentException('The booking schedule ownership is inconsistent.');
        }

        $this->synchronizeBookingDetail($detail, [
            'rate_code' => FacilityRateCode::Both,
        ]);
    }

    public function transferReservationOwnership(
        ReservationDetail $reservationDetail,
        BookingDetail $bookingDetail,
    ): void {
        $this->assertInTransaction();

        $reservationKeys = $this->keyed($this->requiredBlocksForDetail($reservationDetail));
        $bookingKeys = $this->keyed($this->requiredBlocksForDetail($bookingDetail));

        if ($reservationKeys->keys()->all() !== $bookingKeys->keys()->all()) {
            throw new InvalidArgumentException('The converted booking schedule does not match its reservation.');
        }

        $reservationBlocks = $this->ownedBlocks(
            'reservation_detail_id',
            (int) $reservationDetail->reservation_details_id,
        );

        if ($reservationBlocks->count() !== $reservationKeys->count()
            || $reservationBlocks->contains(fn (FacilityScheduleBlock $block): bool => ! $reservationKeys->has(
                $this->key(
                    (int) $block->facility_id,
                    $block->service_date->toDateString(),
                    $block->slot,
                ),
            ))
            || $this->ownedBlocks('booking_detail_id', (int) $bookingDetail->booking_details_id)->isNotEmpty()
        ) {
            throw new InvalidArgumentException('The reservation schedule ownership is inconsistent.');
        }

        FacilityScheduleBlock::query()
            ->where('reservation_detail_id', $reservationDetail->reservation_details_id)
            ->update([
                'reservation_detail_id' => null,
                'booking_detail_id' => $bookingDetail->booking_details_id,
            ]);
    }

    /** @param iterable<int, ReservationDetail> $details */
    public function releaseReservationDetails(iterable $details): void
    {
        $this->release(
            'reservation_detail_id',
            collect($details)->pluck('reservation_details_id')->map(fn (mixed $id): int => (int) $id)->all(),
        );
    }

    /** @param iterable<int, BookingDetail> $details */
    public function releaseBookingDetails(iterable $details): void
    {
        $this->release(
            'booking_detail_id',
            collect($details)->pluck('booking_details_id')->map(fn (mixed $id): int => (int) $id)->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, array{facility_id: int, service_date: string, slot: FacilityScheduleSlot}>
     */
    private function requiredBlocksForDetail(Model $detail, array $attributes = []): array
    {
        $schedulePolicy = $this->attribute($detail, $attributes, 'schedule_policy');
        $rateCode = $this->attribute($detail, $attributes, 'rate_code');
        $checkInDate = $this->attribute($detail, $attributes, 'check_in_date');
        $checkOutDate = $this->attribute($detail, $attributes, 'check_out_date');

        if ((! is_string($schedulePolicy) && ! $schedulePolicy instanceof FacilitySchedulePolicy)
            || (! is_string($rateCode) && ! $rateCode instanceof FacilityRateCode)
            || (! is_string($checkInDate) && ! $checkInDate instanceof DateTimeInterface)
            || (! is_string($checkOutDate) && ! $checkOutDate instanceof DateTimeInterface)
        ) {
            throw new InvalidArgumentException('The facility schedule snapshot is invalid.');
        }

        return $this->requiredBlocks(
            (int) $this->attribute($detail, $attributes, 'facility_id'),
            $schedulePolicy,
            $rateCode,
            $checkInDate,
            $checkOutDate,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function attribute(Model $detail, array $attributes, string $name): mixed
    {
        return array_key_exists($name, $attributes)
            ? $attributes[$name]
            : $detail->getRawOriginal($name);
    }

    /**
     * @param  array<int, array{facility_id: int, service_date: string, slot: FacilityScheduleSlot}>  $desiredBlocks
     */
    private function synchronize(string $ownerColumn, int $ownerId, array $desiredBlocks): void
    {
        $this->assertInTransaction();

        if ($ownerId < 1 || ! in_array($ownerColumn, ['reservation_detail_id', 'booking_detail_id'], true)) {
            throw new LogicException('A valid schedule block owner is required.');
        }

        $desired = $this->keyed($desiredBlocks);
        $current = $this->ownedBlocks($ownerColumn, $ownerId);
        $currentByKey = $current->keyBy(fn (FacilityScheduleBlock $block): string => $this->key(
            (int) $block->facility_id,
            $block->service_date->toDateString(),
            $block->slot,
        ));
        $newBlocks = $desired->diffKeys($currentByKey);

        if ($newBlocks->isNotEmpty()) {
            $conflictingKeys = FacilityScheduleBlock::query()
                ->whereIn('facility_id', $newBlocks->pluck('facility_id')->all())
                ->whereIn('service_date', $newBlocks->pluck('service_date')->all())
                ->whereIn('slot', $newBlocks->pluck('slot')->map->value->all())
                ->lockForUpdate()
                ->get()
                ->toBase()
                ->map(fn (FacilityScheduleBlock $block): string => $this->key(
                    (int) $block->facility_id,
                    $block->service_date->toDateString(),
                    $block->slot,
                ));

            if ($conflictingKeys->intersect($newBlocks->keys())->isNotEmpty()) {
                throw $this->conflict();
            }
        }

        try {
            foreach ($newBlocks as $block) {
                FacilityScheduleBlock::query()->create([
                    'facility_id' => $block['facility_id'],
                    'service_date' => $block['service_date'],
                    'slot' => $block['slot'],
                    'reservation_detail_id' => $ownerColumn === 'reservation_detail_id' ? $ownerId : null,
                    'booking_detail_id' => $ownerColumn === 'booking_detail_id' ? $ownerId : null,
                ]);
            }
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->conflict($exception);
        }

        $obsoleteIds = $current
            ->reject(fn (FacilityScheduleBlock $block): bool => $desired->has($this->key(
                (int) $block->facility_id,
                $block->service_date->toDateString(),
                $block->slot,
            )))
            ->modelKeys();

        if ($obsoleteIds !== []) {
            FacilityScheduleBlock::query()->whereKey($obsoleteIds)->delete();
        }
    }

    /** @return EloquentCollection<int, FacilityScheduleBlock> */
    private function ownedBlocks(string $ownerColumn, int $ownerId): EloquentCollection
    {
        return FacilityScheduleBlock::query()
            ->where($ownerColumn, $ownerId)
            ->orderBy('facility_id')
            ->orderBy('service_date')
            ->orderBy('slot')
            ->lockForUpdate()
            ->get();
    }

    /** @param array<int, int> $ownerIds */
    private function release(string $ownerColumn, array $ownerIds): void
    {
        $this->assertInTransaction();

        if ($ownerIds === []) {
            return;
        }

        $blocks = FacilityScheduleBlock::query()
            ->whereIn($ownerColumn, $ownerIds)
            ->lockForUpdate()
            ->get();

        FacilityScheduleBlock::query()->whereKey($blocks->modelKeys())->delete();
    }

    /**
     * @param  array<int, array{facility_id: int, service_date: string, slot: FacilityScheduleSlot}>  $blocks
     * @return Collection<string, array{facility_id: int, service_date: string, slot: FacilityScheduleSlot}>
     */
    private function keyed(array $blocks): Collection
    {
        return collect($blocks)
            ->keyBy(fn (array $block): string => $this->key(
                $block['facility_id'],
                $block['service_date'],
                $block['slot'],
            ))
            ->sortKeys();
    }

    private function key(int $facilityId, string $serviceDate, FacilityScheduleSlot $slot): string
    {
        return $facilityId.'|'.$serviceDate.'|'.$slot->value;
    }

    /** @return array<int, array{facility_id: int, service_date: string, slot: FacilityScheduleSlot}> */
    private function overnightBlocks(
        int $facilityId,
        FacilityRateCode $rateCode,
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
    ): array {
        if ($rateCode !== FacilityRateCode::Overnight || ! $checkOut->greaterThan($checkIn)) {
            throw new InvalidArgumentException('Room check-out must be after check-in.');
        }

        $blocks = [];

        for ($serviceDate = $checkIn; $serviceDate->lessThan($checkOut); $serviceDate = $serviceDate->addDay()) {
            $blocks[] = $this->block($facilityId, $serviceDate, FacilityScheduleSlot::Overnight);
        }

        return $blocks;
    }

    /** @return array<int, array{facility_id: int, service_date: string, slot: FacilityScheduleSlot}> */
    private function datedSlotBlocks(
        int $facilityId,
        FacilityRateCode $rateCode,
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
    ): array {
        $slots = match ($rateCode) {
            FacilityRateCode::Day => [FacilityScheduleSlot::Day],
            FacilityRateCode::Night => [FacilityScheduleSlot::Night],
            FacilityRateCode::Both => [FacilityScheduleSlot::Day, FacilityScheduleSlot::Night],
            default => throw new InvalidArgumentException('The cottage schedule and rate are inconsistent.'),
        };

        $blocks = [];

        foreach ($this->serviceDates($checkIn, $checkOut) as $serviceDate) {
            foreach ($slots as $slot) {
                $blocks[] = $this->block($facilityId, $serviceDate, $slot);
            }
        }

        return $blocks;
    }

    /** @return array<int, array{facility_id: int, service_date: string, slot: FacilityScheduleSlot}> */
    private function wholeDayBlocks(
        int $facilityId,
        FacilityRateCode $rateCode,
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
    ): array {
        if ($rateCode !== FacilityRateCode::WholeDay) {
            throw new InvalidArgumentException('The function-hall schedule and rate are inconsistent.');
        }

        return array_map(
            fn (CarbonImmutable $serviceDate): array => $this->block(
                $facilityId,
                $serviceDate,
                FacilityScheduleSlot::WholeDay,
            ),
            $this->serviceDates($checkIn, $checkOut),
        );
    }

    /** @return array<int, CarbonImmutable> */
    private function serviceDates(CarbonImmutable $checkIn, CarbonImmutable $checkOut): array
    {
        if ($checkIn->equalTo($checkOut)) {
            return [$checkIn];
        }

        $dates = [];

        for ($serviceDate = $checkIn; $serviceDate->lessThan($checkOut); $serviceDate = $serviceDate->addDay()) {
            $dates[] = $serviceDate;
        }

        return $dates;
    }

    /** @return array{facility_id: int, service_date: string, slot: FacilityScheduleSlot} */
    private function block(
        int $facilityId,
        CarbonImmutable $serviceDate,
        FacilityScheduleSlot $slot,
    ): array {
        return [
            'facility_id' => $facilityId,
            'service_date' => $serviceDate->toDateString(),
            'slot' => $slot,
        ];
    }

    private function assertInTransaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Facility schedule blocks may only be changed inside a database transaction.');
        }
    }

    private function conflict(?UniqueConstraintViolationException $previous = null): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'The selected facility is no longer available for the requested schedule.',
            previous: $previous,
        );
    }
}

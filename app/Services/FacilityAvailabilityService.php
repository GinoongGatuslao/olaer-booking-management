<?php

namespace App\Services;

use App\FacilityRateCode;
use App\FacilityScheduleSlot;
use App\Models\BookingDetail;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\ProductRate;
use App\Models\ReservationDetail;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class FacilityAvailabilityService
{
    public function __construct(
        private readonly FacilityScheduleBlockService $scheduleBlocks,
    ) {}

    public function isAvailable(
        int $facilityId,
        string $checkInDate,
        string $checkOutDate,
        ?int $excludeReservationId = null,
        ?int $excludeBookingId = null
    ): bool {
        $facility = Facility::query()->find($facilityId);

        if (! $facility || $facility->facility_status !== 'Available') {
            return false;
        }

        [$requestedStart, $requestedEnd] = $this->normalizedPeriod($checkInDate, $checkOutDate);

        $reservationDetails = ReservationDetail::query()
            ->with('reservation')
            ->where('facility_id', $facilityId)
            ->get();

        foreach ($reservationDetails as $detail) {
            if (! $detail->reservation) {
                continue;
            }

            if ($excludeReservationId && (int) $detail->reservation_id === $excludeReservationId) {
                continue;
            }

            if (in_array($detail->reservation->status, ['Cancelled', 'Converted', 'No-show'], true)) {
                continue;
            }

            [$existingStart, $existingEnd] = $this->normalizedPeriod(
                (string) $detail->check_in_date,
                (string) $detail->check_out_date,
            );

            if ($this->periodsOverlap($requestedStart, $requestedEnd, $existingStart, $existingEnd)) {
                return false;
            }
        }

        $bookingDetails = BookingDetail::query()
            ->with('booking')
            ->where('facility_id', $facilityId)
            ->get();

        foreach ($bookingDetails as $detail) {
            if (! $detail->booking) {
                continue;
            }

            if ($excludeBookingId && (int) $detail->booking_id === $excludeBookingId) {
                continue;
            }

            if (in_array($detail->status, ['Cancelled', 'Transferred'], true)) {
                continue;
            }

            if (in_array($detail->booking->status, ['Cancelled'], true)) {
                continue;
            }

            [$existingStart, $existingEnd] = $this->normalizedPeriod(
                (string) $detail->check_in_date,
                (string) $detail->check_out_date,
            );

            if ($this->periodsOverlap($requestedStart, $requestedEnd, $existingStart, $existingEnd)) {
                return false;
            }
        }

        return true;
    }

    /**
     * For same-day cottage/function-hall use, the database stores the same check-in and check-out date.
     * Internally, treat that as one full calendar-day block to avoid double-booking the same facility.
     */
    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function normalizedPeriod(string $checkInDate, string $checkOutDate): array
    {
        $start = CarbonImmutable::parse($checkInDate)->startOfDay();
        $end = CarbonImmutable::parse($checkOutDate)->startOfDay();

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('Check-out date cannot be before check-in date.');
        }

        if ($end->equalTo($start)) {
            $end = $start->addDay();
        }

        return [$start, $end];
    }

    private function periodsOverlap(
        CarbonImmutable $requestedStart,
        CarbonImmutable $requestedEnd,
        CarbonImmutable $existingStart,
        CarbonImmutable $existingEnd
    ): bool {
        return $requestedStart->lessThan($existingEnd) && $requestedEnd->greaterThan($existingStart);
    }

    /** @return Collection<int, int> */
    public function availableFacilityIds(
        int $facilityProductId,
        FacilityRateCode|string $rateCode,
        string $checkInDate,
        string $checkOutDate,
    ): Collection {
        $product = FacilityProduct::query()
            ->with('productRates')
            ->findOrFail($facilityProductId);
        $rateCode = $rateCode instanceof FacilityRateCode
            ? $rateCode
            : FacilityRateCode::tryFrom($rateCode);

        if (! $product->is_active || $rateCode === null) {
            throw new InvalidArgumentException('The selected facility product or rate is inactive.');
        }

        $rateIsActive = $product->productRates->contains(
            fn (ProductRate $rate): bool => $rate->rate_code === $rateCode && $rate->is_active,
        );

        if (! $rateIsActive) {
            throw new InvalidArgumentException('The selected rate is not available for this facility product.');
        }

        $requiredBlocks = $this->scheduleBlocks->requiredBlocks(
            1,
            $product->schedule_policy,
            $rateCode,
            $checkInDate,
            $checkOutDate,
        );

        return Facility::query()
            ->where('facility_product_id', $facilityProductId)
            ->where('facility_status', 'Available')
            ->whereDoesntHave(
                'scheduleBlocks',
                fn (Builder $query): Builder => $this->matchingBlocks($query, $requiredBlocks),
            )
            ->orderBy('facility_id')
            ->pluck('facility_id')
            ->map(fn (mixed $facilityId): int => (int) $facilityId);
    }

    public function availabilityCount(
        int $facilityProductId,
        FacilityRateCode|string $rateCode,
        string $checkInDate,
        string $checkOutDate,
    ): int {
        return $this->availableFacilityIds(
            $facilityProductId,
            $rateCode,
            $checkInDate,
            $checkOutDate,
        )->count();
    }

    /**
     * @param  array<int, array{facility_id: int, service_date: string, slot: FacilityScheduleSlot}>  $requiredBlocks
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function matchingBlocks(Builder $query, array $requiredBlocks): Builder
    {
        return $query->where(function (Builder $query) use ($requiredBlocks): void {
            foreach ($requiredBlocks as $block) {
                $query->orWhere(function (Builder $query) use ($block): void {
                    $query
                        ->where('service_date', $block['service_date'])
                        ->where('slot', $block['slot']->value);
                });
            }
        });
    }
}

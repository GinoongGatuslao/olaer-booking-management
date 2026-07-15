<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Facility;
use App\Models\FacilityPrice;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class BookingQuoteService
{
    public const ROOM_EXTRA_GUEST_FEE = 100.00;
    public const COTTAGE_DAY_TO_NIGHT_EXTENSION_FEE = 100.00;

    public function __construct(
        private readonly FacilityOccupancyService $occupancy,
    ) {}

    public function quote(
        int $facilityId,
        string $rateType,
        int $extraGuestCount = 0,
        ?int $discountId = null,
        ?int $totalGuestCount = null,
    ): array {
        $facility = Facility::query()
            ->with('facilityType')
            ->findOrFail($facilityId);

        $facilityPrice = FacilityPrice::query()
            ->where('facility_id', $facilityId)
            ->where('rate_type', $rateType)
            ->first();

        if (! $facilityPrice) {
            throw new InvalidArgumentException(
                'Selected rate type is not configured for this facility.'
            );
        }

        $resolvedTotalGuestCount = $totalGuestCount
            ?? $this->occupancy->legacyTotalGuestCount(
                $facility,
                $extraGuestCount,
            );

        $occupancy = $this->occupancy->forFacility(
            $facility,
            $resolvedTotalGuestCount,
        );

        $basePrice = (float) $facilityPrice->facility_price;
        $discountAmount = 0.00;
        $discountRate = 0.00;

        if ($discountId !== null) {
            $discount = Discount::query()->find($discountId);

            if (
                $discount
                && $this->discountAppliesToFacility(
                    $discount,
                    $facility,
                )
            ) {
                $discountRate = $this->normalizeDiscountRate(
                    (float) $discount->discount_amount,
                );
                $discountAmount = round(
                    $basePrice * $discountRate,
                    2,
                );
            }
        }

        $extraGuestFee = round(
            $occupancy['paid_extra_guest_count']
            * self::ROOM_EXTRA_GUEST_FEE,
            2,
        );

        $total = max(
            0,
            round(
                ($basePrice - $discountAmount)
                + $extraGuestFee,
                2,
            ),
        );

        return [
            'facility_id' => $facility->facility_id,
            'facility_name' => $facility->facility_name,
            'facility_type' => $facility->facilityType?->facility_type,
            'rate_type' => $rateType,
            'capacity' => $occupancy['capacity'],
            'total_guest_count' => $occupancy['total_guest_count'],
            'included_guest_count' => $occupancy['included_guest_count'],
            'extra_guest_count' => $occupancy['paid_extra_guest_count'],
            'max_paid_extra_guests' => $occupancy['max_paid_extra_guests'],
            'base_price' => $basePrice,
            'discount_rate' => $discountRate,
            'discount_amount' => $discountAmount,
            'extra_guest_fee' => $extraGuestFee,
            'total' => $total,
        ];
    }

    public function priceForFacilityRate(
        int $facilityId,
        string $rateType,
    ): float {
        $price = FacilityPrice::query()
            ->where('facility_id', $facilityId)
            ->where('rate_type', $rateType)
            ->value('facility_price');

        if ($price === null) {
            throw new InvalidArgumentException(
                'Selected rate type is not configured for this facility.'
            );
        }

        return (float) $price;
    }

    private function discountAppliesToFacility(
        Discount $discount,
        Facility $facility,
    ): bool {
        if (strtolower((string) $discount->status) !== 'active') {
            return false;
        }

        $now = Carbon::now();

        if (
            $discount->discount_start
            && $now->lt(Carbon::parse($discount->discount_start))
        ) {
            return false;
        }

        if (
            $discount->discount_end
            && $now->gt(Carbon::parse($discount->discount_end))
        ) {
            return false;
        }

        return match (strtolower(
            (string) $facility->facilityType?->facility_type,
        )) {
            'cottage' => (bool) $discount->app_to_cottage,
            'room' => (bool) $discount->app_to_room,
            'function hall' => (bool) $discount->app_to_function_hall,
            default => false,
        };
    }

    private function normalizeDiscountRate(float $value): float
    {
        if ($value > 1) {
            return $value / 100;
        }

        return max(0, min(1, $value));
    }
}

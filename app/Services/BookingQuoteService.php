<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Facility;
use Illuminate\Support\Carbon;

class BookingQuoteService
{
    public const ROOM_EXTRA_GUEST_FEE = '100.00';

    public const COTTAGE_DAY_TO_NIGHT_EXTENSION_FEE = '100.00';

    public function __construct(
        private readonly FacilityOccupancyService $occupancy,
        private readonly FacilityProductConfigurationService $products,
        private readonly DecimalMoneyService $money,
    ) {}

    public function quote(
        int $facilityId,
        string $rateType,
        int $extraGuestCount = 0,
        ?int $discountId = null,
        ?int $totalGuestCount = null,
    ): array {
        $facility = $this->products->configuredFacility($facilityId);
        $productRate = $this->products->rateFor($facility, $rateType);

        $resolvedTotalGuestCount = $totalGuestCount
            ?? $this->occupancy->legacyTotalGuestCount(
                $facility,
                $extraGuestCount,
            );

        $occupancy = $this->occupancy->forFacility(
            $facility,
            $resolvedTotalGuestCount,
        );

        $basePrice = $this->money->normalize($productRate->amount);
        $discountAmount = '0.00';
        $discountRate = '0.000000';

        if ($discountId !== null) {
            $discount = Discount::query()->find($discountId);

            if (
                $discount
                && $this->discountAppliesToFacility(
                    $discount,
                    $facility,
                )
            ) {
                $discountRate = $this->money->discountRate(
                    $discount->discount_amount,
                );
                $discountAmount = $this->money->percentage(
                    $basePrice,
                    $discountRate,
                );
            }
        }

        $extraGuestFee = $this->money->multiply(
            self::ROOM_EXTRA_GUEST_FEE,
            $occupancy['paid_extra_guest_count'],
        );

        $total = $this->money->maxZero(
            $this->money->add(
                $this->money->subtract($basePrice, $discountAmount),
                $extraGuestFee,
            ),
        );
        $detailSnapshot = $this->products->detailSnapshot(
            facility: $facility,
            rate: $productRate,
            guestCount: $resolvedTotalGuestCount,
            basePrice: $basePrice,
            discountRate: $discountRate,
            discountAmount: $discountAmount,
            extraGuestFee: $extraGuestFee,
            lineTotal: $total,
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
            'detail_snapshot' => $detailSnapshot,
        ];
    }

    public function priceForFacilityRate(
        int $facilityId,
        string $rateType,
    ): string {
        $facility = $this->products->configuredFacility($facilityId);

        return $this->products->rateFor($facility, $rateType)->amount;
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
}

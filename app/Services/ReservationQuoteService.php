<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Facility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ReservationQuoteService
{
    private const ROOM_EXTRA_GUEST_PRICE = '100.00';

    public function __construct(
        private readonly FacilityOccupancyService $occupancy,
        private readonly FacilityProductConfigurationService $products,
        private readonly DecimalMoneyService $money,
    ) {}

    public function quote(
        int $facilityId,
        string $rateType,
        string $checkInDate,
        string $checkOutDate,
        ?int $discountId = null,
        int $extraGuestCount = 0,
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

        $baseUnits = $this->billableUnits(
            $rateType,
            $checkInDate,
            $checkOutDate,
        );
        $basePrice = $this->money->multiply(
            $productRate->amount,
            $baseUnits,
        );
        $extraGuestCharge = $this->money->multiply(
            self::ROOM_EXTRA_GUEST_PRICE,
            $occupancy['paid_extra_guest_count'],
        );
        $discountRate = '0.000000';
        $discountAmount = '0.00';
        $discountName = null;

        if ($discountId) {
            $discount = Discount::query()->find($discountId);

            if (
                ! $discount
                || ! $this->discountAppliesToFacility(
                    $discount,
                    $facility,
                )
            ) {
                throw new InvalidArgumentException(
                    'The selected discount does not apply to this facility.'
                );
            }

            if (! $this->discountIsValidForDate($discount, $checkInDate)) {
                throw new InvalidArgumentException(
                    'The selected discount is inactive or outside its validity period.'
                );
            }

            $discountRate = $this->money->discountRate(
                $discount->discount_amount,
            );
            $discountAmount = $this->money->percentage(
                $basePrice,
                $discountRate,
            );
            $discountName = $discount->discount_name;
        }

        $totalPrice = $this->money->maxZero(
            $this->money->subtract(
                $this->money->add($basePrice, $extraGuestCharge),
                $discountAmount,
            ),
        );
        $detailSnapshot = $this->products->detailSnapshot(
            facility: $facility,
            rate: $productRate,
            guestCount: $resolvedTotalGuestCount,
            basePrice: $basePrice,
            discountRate: $discountRate,
            discountAmount: $discountAmount,
            extraGuestFee: $extraGuestCharge,
            lineTotal: $totalPrice,
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
            'base_units' => $baseUnits,
            'base_price' => $basePrice,
            'extra_guest_charge' => $extraGuestCharge,
            'discount_id' => $discountId,
            'discount_name' => $discountName,
            'discount_rate' => $discountRate,
            'discount_amount' => $discountAmount,
            'total_price' => $totalPrice,
            'amount_due' => $totalPrice,
            'detail_snapshot' => $detailSnapshot,
        ];
    }

    private function billableUnits(
        string $rateType,
        string $checkInDate,
        string $checkOutDate,
    ): int {
        $start = CarbonImmutable::parse($checkInDate)->startOfDay();
        $end = CarbonImmutable::parse($checkOutDate)->startOfDay();

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException(
                'Check-out date cannot be before check-in date.'
            );
        }

        return (int) max($start->diffInDays($end), 1);
    }

    private function discountAppliesToFacility(
        Discount $discount,
        Facility $facility,
    ): bool {
        return match ($facility->facilityType?->facility_type) {
            'Cottage' => (bool) $discount->app_to_cottage,
            'Room' => (bool) $discount->app_to_room,
            'Function Hall' => (bool) $discount->app_to_function_hall,
            default => false,
        };
    }

    private function discountIsValidForDate(
        Discount $discount,
        string $checkInDate,
    ): bool {
        if ($discount->status !== 'Active') {
            return false;
        }

        $effectiveAt = Carbon::parse($checkInDate)
            ->setTime(12, 0, 0);

        if (
            $discount->discount_start
            && $effectiveAt->lt(
                Carbon::parse($discount->discount_start),
            )
        ) {
            return false;
        }

        if (
            $discount->discount_end
            && $effectiveAt->gt(
                Carbon::parse($discount->discount_end),
            )
        ) {
            return false;
        }

        return true;
    }
}

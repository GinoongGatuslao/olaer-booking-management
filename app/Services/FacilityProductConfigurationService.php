<?php

namespace App\Services;

use App\FacilityCapacityPolicy;
use App\FacilityProductCode;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\ProductRate;
use InvalidArgumentException;

class FacilityProductConfigurationService
{
    public function __construct(private readonly DecimalMoneyService $money) {}

    public function configuredFacility(int $facilityId): Facility
    {
        $facility = Facility::query()
            ->with(['facilityType', 'facilityProduct.productRates'])
            ->findOrFail($facilityId);

        $this->productFor($facility);

        return $facility;
    }

    public function productFor(Facility $facility): FacilityProduct
    {
        $facility->loadMissing(['facilityType', 'facilityProduct.productRates']);
        $product = $facility->facilityProduct;

        if (! $product instanceof FacilityProduct
            || ! $product->is_active
            || (int) $product->facility_type_id !== (int) $facility->facility_type_id
            || ! $this->hasValidConfiguration($product)
        ) {
            throw new InvalidArgumentException(
                'The selected facility is not configured for new transactions. Please choose another facility or contact resort staff.',
            );
        }

        return $product;
    }

    public function rateFor(Facility $facility, string $legacyRateType): ProductRate
    {
        $product = $this->productFor($facility);
        $rateCode = $this->rateCodeFor($product, $legacyRateType);
        $rate = $product->productRates->first(
            fn (ProductRate $productRate): bool => $productRate->rate_code === $rateCode
                && $productRate->is_active,
        );

        if (! $rate instanceof ProductRate) {
            throw new InvalidArgumentException(
                'The selected rate is not configured for this facility. Please choose another rate or contact resort staff.',
            );
        }

        return $rate;
    }

    public function canonicalRateType(ProductRate|FacilityRateCode $rate): string
    {
        $rateCode = $rate instanceof ProductRate ? $rate->rate_code : $rate;

        return match ($rateCode) {
            FacilityRateCode::Day => 'Day',
            FacilityRateCode::Night => 'Night',
            FacilityRateCode::Both => 'Both',
            FacilityRateCode::Overnight => 'Overnight',
            FacilityRateCode::WholeDay => 'Whole Day',
        };
    }

    public function canonicalRateCode(string $rateType): FacilityRateCode
    {
        return match ($rateType) {
            'Day' => FacilityRateCode::Day,
            'Night' => FacilityRateCode::Night,
            'Both' => FacilityRateCode::Both,
            'Overnight' => FacilityRateCode::Overnight,
            'Whole Day' => FacilityRateCode::WholeDay,
            default => throw new InvalidArgumentException(
                'The selected rate is not configured for this facility. Please choose another rate or contact resort staff.',
            ),
        };
    }

    public function strictMaximum(?int $facilityId): ?int
    {
        if (! $facilityId) {
            return null;
        }

        $facility = $this->configuredFacility($facilityId);
        $product = $this->productFor($facility);

        return $this->isRoomProduct($product)
            ? $product->strict_maximum
            : null;
    }

    public function isRoomProduct(FacilityProduct $product): bool
    {
        return $product->product_code === FacilityProductCode::RoomStandard;
    }

    /** @return array<string, int|string|null> */
    public function detailSnapshot(
        Facility $facility,
        ProductRate $rate,
        int $guestCount,
        string $basePrice,
        ?string $discountRate,
        string $discountAmount,
        string $extraGuestFee,
        string $lineTotal,
    ): array {
        $product = $this->productFor($facility);

        return [
            'facility_product_id' => (int) $product->facility_product_id,
            'guest_count' => $guestCount,
            'capacity_policy' => $product->capacity_policy->value,
            'included_guest_count_snapshot' => $product->included_guest_count,
            'strict_maximum_snapshot' => $product->strict_maximum,
            'suggested_minimum_snapshot' => $product->suggested_minimum,
            'suggested_maximum_snapshot' => $product->suggested_maximum,
            'schedule_policy' => $product->schedule_policy->value,
            'rate_code' => $rate->rate_code->value,
            'unit_rate' => $rate->amount,
            'base_price' => $basePrice,
            'discount_rate' => $discountRate,
            'discount_amount' => $discountAmount,
            'extra_guest_fee' => $extraGuestFee,
            'line_total' => $lineTotal,
        ];
    }

    private function rateCodeFor(
        FacilityProduct $product,
        string $legacyRateType,
    ): FacilityRateCode {
        return match ($product->schedule_policy) {
            FacilitySchedulePolicy::Overnight => FacilityRateCode::Overnight,
            FacilitySchedulePolicy::WholeCalendarDay => FacilityRateCode::WholeDay,
            FacilitySchedulePolicy::DatedSlots => $this->datedSlotRateCode($legacyRateType),
        };
    }

    private function datedSlotRateCode(string $legacyRateType): FacilityRateCode
    {
        $rateCode = $this->canonicalRateCode($legacyRateType);

        if (! in_array($rateCode, [FacilityRateCode::Day, FacilityRateCode::Night, FacilityRateCode::Both], true)) {
            throw new InvalidArgumentException(
                'The selected rate is not configured for this facility. Please choose another rate or contact resort staff.',
            );
        }

        return $rateCode;
    }

    private function hasValidConfiguration(FacilityProduct $product): bool
    {
        $requiredRates = match ($product->product_code) {
            FacilityProductCode::RoomStandard => [FacilityRateCode::Overnight],
            FacilityProductCode::CottageSmall,
            FacilityProductCode::CottageMedium,
            FacilityProductCode::CottageLarge,
            FacilityProductCode::CottageExtraLarge => [
                FacilityRateCode::Day,
                FacilityRateCode::Night,
                FacilityRateCode::Both,
            ],
            FacilityProductCode::FunctionHall1,
            FacilityProductCode::FunctionHall2 => [FacilityRateCode::WholeDay],
        };

        if ($this->isRoomProduct($product)) {
            if ($product->capacity_policy !== FacilityCapacityPolicy::Strict
                || $product->schedule_policy !== FacilitySchedulePolicy::Overnight
                || $product->included_guest_count === null
                || $product->strict_maximum === null
                || $product->included_guest_count < 1
                || $product->strict_maximum < $product->included_guest_count
            ) {
                return false;
            }
        } elseif ($product->capacity_policy !== FacilityCapacityPolicy::RecommendedInformational
            || $product->suggested_maximum === null
            || $product->suggested_maximum < 1
            || ($product->suggested_minimum !== null
                && ($product->suggested_minimum < 1 || $product->suggested_minimum > $product->suggested_maximum))
            || ($product->schedule_policy === FacilitySchedulePolicy::DatedSlots) !== $this->isCottageProduct($product)
            || ($product->schedule_policy === FacilitySchedulePolicy::WholeCalendarDay) !== $this->isFunctionHallProduct($product)
        ) {
            return false;
        }

        $activeRates = $product->productRates
            ->filter(fn (ProductRate $rate): bool => $rate->is_active)
            ->keyBy(fn (ProductRate $rate): string => $rate->rate_code->value);

        foreach ($requiredRates as $requiredRate) {
            $rate = $activeRates->get($requiredRate->value);

            if (! $rate instanceof ProductRate || $this->money->compare($rate->amount, '0.00') !== 1) {
                return false;
            }
        }

        return $activeRates->count() === count($requiredRates);
    }

    private function isCottageProduct(FacilityProduct $product): bool
    {
        return in_array($product->product_code, [
            FacilityProductCode::CottageSmall,
            FacilityProductCode::CottageMedium,
            FacilityProductCode::CottageLarge,
            FacilityProductCode::CottageExtraLarge,
        ], true);
    }

    private function isFunctionHallProduct(FacilityProduct $product): bool
    {
        return in_array($product->product_code, [
            FacilityProductCode::FunctionHall1,
            FacilityProductCode::FunctionHall2,
        ], true);
    }
}

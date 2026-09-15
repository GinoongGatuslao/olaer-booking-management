<?php

namespace App\Services;

use App\FacilityProductCode;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\ProductRate;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FacilityProductConfigurationService
{
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

        if (
            ! $product instanceof FacilityProduct
            || ! $product->is_active
            || (int) $product->facility_type_id !== (int) $facility->facility_type_id
            || (
                $this->isRoomProduct($product)
                && (
                    $product->included_guest_count === null
                    || $product->strict_maximum === null
                    || $product->included_guest_count < 1
                    || $product->strict_maximum < $product->included_guest_count
                )
            )
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
        string $discountRate,
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
        $normalized = Str::of($legacyRateType)
            ->replace(['_', '-'], ' ')
            ->lower()
            ->squish()
            ->toString();

        if (
            Str::contains($normalized, 'both')
            || (
                Str::contains($normalized, 'day')
                && Str::contains($normalized, 'night')
            )
        ) {
            return FacilityRateCode::Both;
        }

        if (Str::startsWith($normalized, 'day')) {
            return FacilityRateCode::Day;
        }

        if (Str::startsWith($normalized, 'night')) {
            return FacilityRateCode::Night;
        }

        throw new InvalidArgumentException(
            'The selected rate is not configured for this facility. Please choose another rate or contact resort staff.',
        );
    }
}

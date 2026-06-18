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

    public function quote(int $facilityId, string $rateType, int $extraGuestCount = 0, ?int $discountId = null): array
    {
        $facility = Facility::query()
            ->with('facilityType')
            ->findOrFail($facilityId);

        $facilityPrice = FacilityPrice::query()
            ->where('facility_id', $facilityId)
            ->where('rate_type', $rateType)
            ->first();

        if (! $facilityPrice) {
            throw new InvalidArgumentException('Selected rate type is not configured for this facility.');
        }

        $basePrice = (float) $facilityPrice->facility_price;
        $discountAmount = 0.00;
        $discountRate = 0.00;

        if ($discountId !== null) {
            $discount = Discount::query()->find($discountId);

            if ($discount && $this->discountAppliesToFacility($discount, $facility)) {
                $discountRate = $this->normalizeDiscountRate((float) $discount->discount_amount);
                $discountAmount = round($basePrice * $discountRate, 2);
            }
        }

        $extraGuestFee = $this->extraGuestFee($facility, $extraGuestCount);
        $total = max(0, round(($basePrice - $discountAmount) + $extraGuestFee, 2));

        return [
            'facility_id' => $facility->facility_id,
            'facility_name' => $facility->facility_name,
            'facility_type' => optional($facility->facilityType)->facility_type,
            'rate_type' => $rateType,
            'base_price' => $basePrice,
            'discount_rate' => $discountRate,
            'discount_amount' => $discountAmount,
            'extra_guest_count' => $extraGuestCount,
            'extra_guest_fee' => $extraGuestFee,
            'total' => $total,
        ];
    }

    public function priceForFacilityRate(int $facilityId, string $rateType): float
    {
        $price = FacilityPrice::query()
            ->where('facility_id', $facilityId)
            ->where('rate_type', $rateType)
            ->value('facility_price');

        if ($price === null) {
            throw new InvalidArgumentException('Selected rate type is not configured for this facility.');
        }

        return (float) $price;
    }

    private function discountAppliesToFacility(Discount $discount, Facility $facility): bool
    {
        if (strtolower((string) $discount->status) !== 'active') {
            return false;
        }

        $now = Carbon::now();

        if ($discount->discount_start && $now->lt(Carbon::parse($discount->discount_start))) {
            return false;
        }

        if ($discount->discount_end && $now->gt(Carbon::parse($discount->discount_end))) {
            return false;
        }

        $facilityType = strtolower((string) optional($facility->facilityType)->facility_type);

        if ($facilityType === 'cottage') {
            return (bool) $discount->app_to_cottage;
        }

        if ($facilityType === 'room') {
            return (bool) $discount->app_to_room;
        }

        if ($facilityType === 'function hall') {
            return (bool) $discount->app_to_function_hall;
        }

        return false;
    }

    private function normalizeDiscountRate(float $value): float
    {
        if ($value > 1) {
            return $value / 100;
        }

        return max(0, min(1, $value));
    }

    private function extraGuestFee(Facility $facility, int $extraGuestCount): float
    {
        if ($extraGuestCount <= 0) {
            return 0.00;
        }

        $facilityType = strtolower((string) optional($facility->facilityType)->facility_type);

        if ($facilityType !== 'room') {
            return 0.00;
        }

        return round($extraGuestCount * self::ROOM_EXTRA_GUEST_FEE, 2);
    }
}

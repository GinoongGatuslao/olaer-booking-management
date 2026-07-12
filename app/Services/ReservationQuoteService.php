<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Facility;
use App\Models\FacilityPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ReservationQuoteService
{
    private const ROOM_EXTRA_GUEST_PRICE = 100.00;

    public function quote(
        int $facilityId,
        string $rateType,
        string $checkInDate,
        string $checkOutDate,
        ?int $discountId = null,
        int $extraGuestCount = 0
    ): array {
        $facility = Facility::query()
            ->with('facilityType')
            ->findOrFail($facilityId);

        $price = FacilityPrice::query()
            ->where('facility_id', $facilityId)
            ->where('rate_type', $rateType)
            ->first();

        if (! $price) {
            throw new InvalidArgumentException('The selected rate type is not configured for this facility.');
        }

        $baseUnits = $this->billableUnits($rateType, $checkInDate, $checkOutDate);
        $basePrice = (float) $price->facility_price * $baseUnits;
        $extraGuestCharge = $this->extraGuestCharge($facility, $extraGuestCount);
        $discountAmount = 0.00;
        $discountName = null;

        if ($discountId) {
            $discount = Discount::query()->find($discountId);

            if (! $discount || ! $this->discountAppliesToFacility($discount, $facility)) {
                throw new InvalidArgumentException('The selected discount does not apply to this facility.');
            }

            if (! $this->discountIsValidForDate($discount, $checkInDate)) {
                throw new InvalidArgumentException('The selected discount is inactive or outside its validity period.');
            }

            $discountRate = $this->normalizeDiscountRate((float) $discount->discount_amount);
            $discountAmount = round($basePrice * $discountRate, 2);
            $discountName = $discount->discount_name;
        }

        $totalPrice = max(round($basePrice + $extraGuestCharge - $discountAmount, 2), 0);

        return [
            'facility_id' => $facility->facility_id,
            'facility_name' => $facility->facility_name,
            'facility_type' => $facility->facilityType?->facility_type,
            'rate_type' => $rateType,
            'base_units' => $baseUnits,
            'base_price' => round($basePrice, 2),
            'extra_guest_count' => $extraGuestCount,
            'extra_guest_charge' => round($extraGuestCharge, 2),
            'discount_id' => $discountId,
            'discount_name' => $discountName,
            'discount_amount' => $discountAmount,
            'total_price' => $totalPrice,
            'amount_due' => $totalPrice,
        ];
    }

    private function billableUnits(string $rateType, string $checkInDate, string $checkOutDate): int
    {
        $start = CarbonImmutable::parse($checkInDate)->startOfDay();
        $end = CarbonImmutable::parse($checkOutDate)->startOfDay();

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('Check-out date cannot be before check-in date.');
        }

        return (int) max($start->diffInDays($end), 1);
    }

    private function extraGuestCharge(Facility $facility, int $extraGuestCount): float
    {
        if ($extraGuestCount <= 0) {
            return 0.00;
        }

        if ($facility->facilityType?->facility_type !== 'Room') {
            return 0.00;
        }

        if ($extraGuestCount > 6) {
            throw new InvalidArgumentException('Rooms allow up to 6 extra guests only, based on the 4 default / 10 maximum guest rule.');
        }

        return $extraGuestCount * self::ROOM_EXTRA_GUEST_PRICE;
    }

    private function discountAppliesToFacility(Discount $discount, Facility $facility): bool
    {
        return match ($facility->facilityType?->facility_type) {
            'Cottage' => (bool) $discount->app_to_cottage,
            'Room' => (bool) $discount->app_to_room,
            'Function Hall' => (bool) $discount->app_to_function_hall,
            default => false,
        };
    }

    private function discountIsValidForDate(Discount $discount, string $checkInDate): bool
    {
        if ($discount->status !== 'Active') {
            return false;
        }

        $effectiveAt = Carbon::parse($checkInDate)->setTime(12, 0, 0);

        if ($discount->discount_start && $effectiveAt->lt(Carbon::parse($discount->discount_start))) {
            return false;
        }

        if ($discount->discount_end && $effectiveAt->gt(Carbon::parse($discount->discount_end))) {
            return false;
        }

        return true;
    }

    private function normalizeDiscountRate(float $value): float
    {
        return $value > 1 ? min($value / 100, 1) : max($value, 0);
    }
}

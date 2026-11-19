<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\Facility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DiscountResolverService
{
    /**
     * Resolve the facility discount that should be used for a reservation/booking date.
     *
     * Rule:
     * - If an existing/preferred discount still applies, keep it.
     * - Otherwise, use the highest active applicable discount for the selected facility/date.
     * - If none applies, return null.
     */
    public function resolveForFacility(int $facilityId, string|CarbonInterface $effectiveDate, ?int $preferredDiscountId = null): ?Discount
    {
        $facility = Facility::query()
            ->with('facilityType')
            ->findOrFail($facilityId);

        $effectiveAt = $this->effectiveDateTime($effectiveDate);

        if ($preferredDiscountId !== null) {
            $preferred = Discount::query()->find($preferredDiscountId);

            if ($preferred && $this->appliesToFacilityOn($preferred, $facility, $effectiveAt)) {
                return $preferred;
            }
        }

        return Discount::query()
            ->where('status', 'Active')
            ->whereNotNull('discount_start')
            ->whereNotNull('discount_end')
            ->where($this->facilityApplicabilityColumn($facility), true)
            ->where('discount_start', '<=', $effectiveAt)
            ->where('discount_end', '>=', $effectiveAt)
            ->orderByDesc('discount_amount')
            ->orderBy('discount_name')
            ->first();
    }

    public function appliesToFacilityOn(Discount $discount, Facility $facility, string|CarbonInterface $effectiveDate): bool
    {
        if ((string) $discount->status !== 'Active') {
            return false;
        }

        $column = $this->facilityApplicabilityColumn($facility);

        if (! (bool) $discount->{$column}) {
            return false;
        }

        if ($discount->discount_start === null || $discount->discount_end === null) {
            return false;
        }

        $effectiveAt = $this->effectiveDateTime($effectiveDate);

        if ($effectiveAt->lt(Carbon::parse($discount->discount_start))) {
            return false;
        }

        if ($effectiveAt->gt(Carbon::parse($discount->discount_end))) {
            return false;
        }

        return true;
    }

    public function normalizedRate(?Discount $discount): float
    {
        if (! $discount) {
            return 0.0;
        }

        $value = (float) $discount->discount_amount;

        return $value > 1 ? min($value / 100, 1) : max($value, 0);
    }

    private function facilityApplicabilityColumn(Facility $facility): string
    {
        $facilityType = strtolower((string) $facility->facilityType?->facility_type);

        return match ($facilityType) {
            'cottage' => 'app_to_cottage',
            'room' => 'app_to_room',
            'function hall' => 'app_to_function_hall',
            default => 'app_to_cottage',
        };
    }

    /**
     * A discount depends on when the reservation/booking is created, not on the
     * check-in or check-out date. So it is always checked against the current time:
     * a scheduled discount (not started yet) or an expired one never applies.
     * The date argument is kept so existing callers do not change.
     */
    private function effectiveDateTime(string|CarbonInterface $date): Carbon
    {
        return Carbon::now();
    }
}
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
            ->where($this->facilityApplicabilityColumn($facility), true)
            ->where(function (Builder $query) use ($effectiveAt): void {
                $query->whereNull('discount_start')
                    ->orWhere('discount_start', '<=', $effectiveAt);
            })
            ->where(function (Builder $query) use ($effectiveAt): void {
                $query->whereNull('discount_end')
                    ->orWhere('discount_end', '>=', $effectiveAt);
            })
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

        $effectiveAt = $this->effectiveDateTime($effectiveDate);

        if ($discount->discount_start && $effectiveAt->lt(Carbon::parse($discount->discount_start))) {
            return false;
        }

        if ($discount->discount_end && $effectiveAt->gt(Carbon::parse($discount->discount_end))) {
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

    private function effectiveDateTime(string|CarbonInterface $date): Carbon
    {
        $carbon = $date instanceof CarbonInterface
            ? Carbon::instance($date->toDateTime())
            : Carbon::parse($date);

        // Public reservation forms currently collect dates, not exact facility-use time.
        // Noon keeps same-day discounts usable when discounts start in the morning.
        return $carbon->copy()->setTime(12, 0, 0);
    }
}

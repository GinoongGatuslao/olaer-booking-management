<?php

namespace App\Services;

use App\FacilityRateCode;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\ProductRate;
use Illuminate\Support\Collection;

class PublicBookingSearchService
{
    public function __construct(
        private readonly BookingAvailabilityService $availability,
        private readonly BookingQuoteService $quoteService,
        private readonly FacilityOccupancyService $occupancy,
        private readonly FacilityProductConfigurationService $products,
    ) {}

    public function facilityTypes(): Collection
    {
        return FacilityType::query()
            ->whereHas('facilities')
            ->orderBy('facility_type')
            ->get();
    }

    public function rateTypesForFacilityType(?int $facilityTypeId): Collection
    {
        if (! $facilityTypeId) {
            return collect();
        }

        return ProductRate::query()
            ->select('tbl_facility_product_rate.rate_code')
            ->join('tbl_facility_product', 'tbl_facility_product.facility_product_id', '=', 'tbl_facility_product_rate.facility_product_id')
            ->where('tbl_facility_product.facility_type_id', $facilityTypeId)
            ->where('tbl_facility_product.is_active', true)
            ->where('tbl_facility_product_rate.is_active', true)
            ->distinct()
            ->pluck('tbl_facility_product_rate.rate_code')
            ->map(fn (\App\FacilityRateCode|string $rateCode): string => $this->products->canonicalRateType(
                $rateCode instanceof FacilityRateCode ? $rateCode : FacilityRateCode::from($rateCode),
            ))
            ->unique()
            ->sort()
            ->values();
    }

    public function availableFacilities(?int $facilityTypeId, ?string $rateType, ?string $checkInDate, ?string $checkOutDate): Collection
    {
        if (! $facilityTypeId || blank($rateType) || blank($checkInDate) || blank($checkOutDate)) {
            return collect();
        }

        try {
            $rateCode = $this->products->canonicalRateCode($rateType);
        } catch (\InvalidArgumentException) {
            return collect();
        }

        $facilities = Facility::query()
            ->with(['facilityType', 'facilityProduct', 'prices'])
            ->where('facility_type_id', $facilityTypeId)
            ->where('facility_status', 'Available')
            ->whereHas('facilityProduct.productRates', function ($query) use ($rateCode): void {
                $query->where('rate_code', $rateCode->value)->where('is_active', true);
            })
            ->orderBy('facility_name')
            ->get();

        return $facilities
            ->filter(function (Facility $facility) use ($checkInDate, $checkOutDate): bool {
                return $this->availability->isFacilityAvailable(
                    (int) $facility->facility_id,
                    (string) $checkInDate,
                    (string) $checkOutDate,
                );
            })
            ->values();
    }

    public function quotePreview(
        ?int $facilityId,
        ?string $rateType,
        int $extraGuestCount = 0,
        ?int $totalGuestCount = null,
    ): ?array {
        if (! $facilityId || blank($rateType)) {
            return null;
        }

        return $this->quoteService->quote(
            facilityId: (int) $facilityId,
            rateType: (string) $rateType,
            extraGuestCount: $extraGuestCount,
            discountId: null,
            totalGuestCount: $totalGuestCount,
        );
    }

    public function occupancyPreview(
        ?int $facilityId,
        int $totalGuestCount,
    ): ?array {
        if (! $facilityId) {
            return null;
        }

        return $this->occupancy->forFacilityId(
            $facilityId,
            $totalGuestCount,
        );
    }

    public function maxTotalGuests(?int $facilityId): int
    {
        return $this->occupancy->maxTotalGuests($facilityId);
    }

    public function strictMaximum(?int $facilityId): ?int
    {
        return $this->occupancy->strictMaximum($facilityId);
    }

    public function maxExtraGuests(?int $facilityId): int
    {
        return $this->occupancy->maxPaidExtraGuests($facilityId);
    }
}

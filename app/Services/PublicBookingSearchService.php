<?php

namespace App\Services;

use App\Models\Facility;
use App\Models\FacilityPrice;
use App\Models\FacilityType;
use Illuminate\Support\Collection;

class PublicBookingSearchService
{
    public function __construct(
        private readonly BookingAvailabilityService $availability,
        private readonly BookingQuoteService $quoteService,
        private readonly FacilityOccupancyService $occupancy,
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

        return FacilityPrice::query()
            ->select('tbl_facility_price.rate_type')
            ->join('tbl_facility', 'tbl_facility.facility_id', '=', 'tbl_facility_price.facility_id')
            ->where('tbl_facility.facility_type_id', $facilityTypeId)
            ->distinct()
            ->orderBy('tbl_facility_price.rate_type')
            ->pluck('tbl_facility_price.rate_type');
    }

    public function availableFacilities(?int $facilityTypeId, ?string $rateType, ?string $checkInDate, ?string $checkOutDate): Collection
    {
        if (! $facilityTypeId || blank($rateType) || blank($checkInDate) || blank($checkOutDate)) {
            return collect();
        }

        $facilities = Facility::query()
            ->with(['facilityType', 'prices'])
            ->where('facility_type_id', $facilityTypeId)
            ->where('facility_status', 'Available')
            ->whereHas('prices', function ($query) use ($rateType): void {
                $query->where('rate_type', $rateType);
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

    public function maxExtraGuests(?int $facilityId): int
    {
        return $this->occupancy->maxPaidExtraGuests($facilityId);
    }
}

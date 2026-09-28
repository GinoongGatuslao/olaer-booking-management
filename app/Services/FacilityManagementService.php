<?php

namespace App\Services;

use App\Models\Facility;
use App\Models\FacilityPrice;
use App\Models\FacilityProduct;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FacilityManagementService
{
    public function __construct(
        private readonly FacilityProductConfigurationService $products,
    ) {}

    public function cloneFacility(
        int $sourceFacilityId,
        string $facilityNumber,
        string $facilityName,
        string $status = 'Available',
    ): Facility {
        $facilityNumber = Str::upper(Str::squish($facilityNumber));
        $facilityName = Str::squish($facilityName);

        if ($facilityNumber === '' || $facilityName === '') {
            throw new InvalidArgumentException(
                'Facility number and name are required for the clone.',
            );
        }

        if (! in_array($status, ['Available', 'Unavailable'], true)) {
            throw new InvalidArgumentException(
                'Initial facility status must be Available or Unavailable.',
            );
        }

        return DB::transaction(function () use (
            $sourceFacilityId,
            $facilityNumber,
            $facilityName,
            $status,
        ): Facility {
            $source = Facility::query()
                ->with([
                    'facilityType',
                    'facilityProduct.productRates',
                    'prices',
                    'facilityAmenities',
                ])
                ->whereKey($sourceFacilityId)
                ->lockForUpdate()
                ->firstOrFail();

            if (Facility::query()
                ->where('facility_number', $facilityNumber)
                ->exists()) {
                throw new InvalidArgumentException(
                    'This facility number is already in use.',
                );
            }

            $clone = Facility::query()->create([
                // Deliberately supplied by the operator; source number is never copied.
                'facility_number' => $facilityNumber,
                'facility_name' => $facilityName,
                'facility_type_id' => $source->facility_type_id,
                'facility_product_id' => $source->facility_product_id,
                'facility_size' => $source->facility_size,
                'facility_status' => $status,
                'capacity' => $source->capacity,
                'min_capacity' => $source->min_capacity,
                'max_capacity' => $source->max_capacity,
            ]);

            foreach ($source->prices as $price) {
                FacilityPrice::query()->create([
                    'facility_id' => $clone->facility_id,
                    'rate_type' => $price->rate_type,
                    'facility_price' => $price->facility_price,
                ]);
            }

            foreach ($source->facilityAmenities as $facilityAmenity) {
                $clone->facilityAmenities()->create([
                    'amenity_id' => $facilityAmenity->amenity_id,
                    'amenity_quantity' => $facilityAmenity->amenity_quantity,
                ]);
            }

            return $clone->fresh([
                'facilityType',
                'facilityProduct',
                'prices',
                'facilityAmenities',
            ]);
        }, attempts: 3);
    }

    public function suggestedCloneNumber(Facility $source): string
    {
        $product = $source->facilityProduct;

        if ($product === null) {
            throw new InvalidArgumentException(
                'The source facility has no normalized product configuration.',
            );
        }

        $prefix = $this->numberPrefix($product);

        return $prefix.'-'.str_pad(
            (string) $this->nextSequence($prefix),
            3,
            '0',
            STR_PAD_LEFT,
        );
    }

    public function updateIdentity(
        Facility $facility,
        string $facilityNumber,
        string $facilityName,
        string $status,
    ): Facility {
        $facilityNumber = Str::upper(Str::squish($facilityNumber));
        $facilityName = Str::squish($facilityName);

        if ($facilityNumber === '' || $facilityName === '') {
            throw new InvalidArgumentException('Facility number and name are required.');
        }

        if (! in_array($status, ['Available', 'Unavailable', 'Booked', 'Occupied'], true)) {
            throw new InvalidArgumentException('Facility status is invalid.');
        }

        if (
            in_array($facility->facility_status, ['Booked', 'Occupied'], true)
            && $status !== $facility->facility_status
        ) {
            throw new InvalidArgumentException('Booking and check-in workflows control operational facility status.');
        }

        $duplicate = Facility::query()
            ->where('facility_number', $facilityNumber)
            ->where($facility->getKeyName(), '!=', $facility->getKey())
            ->exists();

        if ($duplicate) {
            throw new InvalidArgumentException('This facility number is already in use.');
        }

        $facility->update([
            'facility_number' => $facilityNumber,
            'facility_name' => $facilityName,
            'facility_status' => $status,
        ]);

        return $facility->fresh(['facilityType', 'facilityProduct']);
    }

    private function numberPrefix(FacilityProduct $product): string
    {
        return collect(explode('_', $product->product_code->value))
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 3)))
            ->implode('-');
    }

    private function nextSequence(string $prefix): int
    {
        $numbers = Facility::query()
            ->where('facility_number', 'like', $prefix.'-%')
            ->pluck('facility_number');

        $maximum = $numbers
            ->map(fn (string $number): int => (int) Str::afterLast($number, '-'))
            ->max();

        return (int) ($maximum ?? 0) + 1;
    }

    private function capacityLabel(FacilityProduct $product): string
    {
        if ($product->strict_maximum !== null) {
            return (string) $product->strict_maximum;
        }

        if ($product->suggested_minimum !== null) {
            return $product->suggested_minimum.'-'.$product->suggested_maximum;
        }

        return (string) ($product->suggested_maximum ?? 1);
    }
}

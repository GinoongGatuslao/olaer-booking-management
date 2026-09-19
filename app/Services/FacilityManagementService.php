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

    /** @return Collection<int, Facility> */
    public function createFromPreset(
        int $facilityProductId,
        int $quantity,
        ?string $namePrefix = null,
        string $status = 'Available',
    ): Collection {
        if ($quantity < 1 || $quantity > 100) {
            throw new InvalidArgumentException('Facility quantity must be between 1 and 100.');
        }

        if (! in_array($status, ['Available', 'Unavailable'], true)) {
            throw new InvalidArgumentException('Facility status must be Available or Unavailable.');
        }

        return DB::transaction(function () use ($facilityProductId, $quantity, $namePrefix, $status): Collection {
            $product = FacilityProduct::query()
                ->with('productRates')
                ->whereKey($facilityProductId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $product->is_active) {
                throw new InvalidArgumentException('Inactive facility presets cannot create new facilities.');
            }

            Facility::query()->orderBy('facility_id')->lockForUpdate()->get(['facility_id']);

            $prefix = $this->numberPrefix($product);
            $nextSequence = $this->nextSequence($prefix);
            $created = new Collection;

            for ($offset = 0; $offset < $quantity; $offset++) {
                $sequence = $nextSequence + $offset;
                $facilityNumber = $prefix.'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
                $facility = Facility::query()->create([
                    'facility_number' => $facilityNumber,
                    'facility_name' => trim((string) $namePrefix) !== ''
                        ? trim((string) $namePrefix).' '.$sequence
                        : $product->display_name.' '.$sequence,
                    'facility_type_id' => $product->facility_type_id,
                    'facility_product_id' => $product->facility_product_id,
                    'facility_size' => $product->size_label ?: $product->display_name,
                    'facility_status' => $status,
                    'capacity' => $this->capacityLabel($product),
                ]);

                foreach ($product->productRates as $rate) {
                    if (! $rate->is_active) {
                        continue;
                    }

                    FacilityPrice::query()->create([
                        'facility_id' => $facility->facility_id,
                        'rate_type' => $this->products->canonicalRateType($rate),
                        'facility_price' => $rate->amount,
                    ]);
                }

                $created->push($facility);
            }

            return $created;
        });
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

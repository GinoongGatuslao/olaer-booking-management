<?php

namespace App\Services;

use App\FacilityCapacityPolicy;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\Facility;
use App\Models\FacilityAmenity;
use App\Models\FacilityPrice;
use App\Models\FacilityProduct;
use App\Models\FacilityType;
use App\Models\ProductRate;
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

    /**
     * Unique facility sizes of one type, with the shared capacity, product code
     * and per-rate prices taken from a facility of that size.
     *
     * @return array<string, array{size: string, count: int, min: int, max: int, product_code: ?string, prices: array<string, string>}>
     */
    public function sizeProfiles(int $facilityTypeId): array
    {
        $profiles = [];

        Facility::query()
            ->with(['prices', 'facilityProduct'])
            ->where('facility_type_id', $facilityTypeId)
            ->whereNotNull('facility_size')
            ->orderBy('facility_size')
            ->orderBy('facility_id')
            ->get()
            ->each(function (Facility $facility) use (&$profiles): void {
                $size = (string) $facility->facility_size;

                if (isset($profiles[$size])) {
                    $profiles[$size]['count']++;

                    return;
                }

                $profiles[$size] = [
                    'size' => $size,
                    'count' => 1,
                    'min' => (int) $facility->min_capacity,
                    'max' => (int) $facility->max_capacity,
                    'product_code' => $facility->facilityProduct?->product_code,
                    'prices' => $facility->prices
                        ->mapWithKeys(fn (FacilityPrice $price): array => [
                            (string) $price->rate_type => number_format((float) $price->facility_price, 2, '.', ''),
                        ])
                        ->all(),
                ];
            });

        return $profiles;
    }

    /** Rate rows a facility type needs: rooms are overnight, everything else is Day / Night / Both. */
    public function rateTypesFor(?string $facilityTypeName): array
    {
        return $facilityTypeName === 'Room'
            ? ['Overnight']
            : ['Day Rate', 'Night Rate', 'Both'];
    }

    public function suggestedProductCode(int $facilityTypeId, string $size): string
    {
        $type = FacilityType::query()->findOrFail($facilityTypeId);
        $base = $this->slugCode($type->facility_type.'_'.$size);

        return $this->uniqueProductCode($base);
    }

    /**
     * Create or update a physical facility together with its product (code,
     * capacity policy, schedule policy) and product rates.
     *
     * Facilities of the same size share one product and one price per rate.
     * Different prices for an existing size are rejected unless $applyToSize
     * is true, in which case every facility of that size is repriced.
     *
     * @param array{
     *     facility_name: string,
     *     facility_type_id: int,
     *     facility_size: string,
     *     min_capacity: int,
     *     max_capacity: int,
     *     facility_status: string,
     *     product_code?: ?string,
     *     prices: array<string, string|float|int>,
     *     amenities?: array<int, array{amenity_id: int, amenity_quantity: int}>,
     *     apply_to_size?: bool,
     * } $data
     */
    public function saveFacility(?Facility $facility, array $data): Facility
    {
        $name = Str::squish($data['facility_name']);
        $size = Str::squish($data['facility_size']);
        $min = (int) $data['min_capacity'];
        $max = (int) $data['max_capacity'];
        $applyToSize = (bool) ($data['apply_to_size'] ?? false);

        if ($name === '' || $size === '') {
            throw new InvalidArgumentException('Facility number and facility name are required.');
        }

        if ($min < 1 || $max < $min) {
            throw new InvalidArgumentException('Maximum capacity must be at least the minimum capacity, and both must be 1 or more.');
        }

        return DB::transaction(function () use ($facility, $data, $name, $size, $min, $max, $applyToSize): Facility {
            $type = FacilityType::query()->findOrFail((int) $data['facility_type_id']);
            $isRoom = $type->facility_type === 'Room';
            $prices = $this->normalizePrices($data['prices'], $this->rateTypesFor($type->facility_type));

            $facilityNumber = Str::upper($name);

            $numberTaken = Facility::query()
                ->where('facility_number', $facilityNumber)
                ->when($facility, fn ($query) => $query->where($facility->getKeyName(), '!=', $facility->getKey()))
                ->exists();

            if ($numberTaken) {
                throw new InvalidArgumentException('This facility number is already in use.');
            }

            // Another facility already defines this size: share its product and prices.
            $sibling = Facility::query()
                ->with(['prices', 'facilityProduct.productRates'])
                ->where('facility_type_id', $type->facility_type_id)
                ->where('facility_size', $size)
                ->when($facility, fn ($query) => $query->where($facility->getKeyName(), '!=', $facility->getKey()))
                ->whereNotNull('facility_product_id')
                ->orderBy('facility_id')
                ->first();

            $product = $sibling?->facilityProduct;
            $sizeIsShared = $product instanceof FacilityProduct;
            $writeProductRates = false;

            if ($sizeIsShared) {
                // Capacity belongs to the size, not to one facility.
                $min = (int) $sibling->min_capacity;
                $max = (int) $sibling->max_capacity;

                $current = $sibling->prices->mapWithKeys(fn (FacilityPrice $price): array => [
                    (string) $price->rate_type => number_format((float) $price->facility_price, 2, '.', ''),
                ]);

                $differs = collect($prices)->contains(
                    fn (string $amount, string $rateType): bool => ($current[$rateType] ?? null) !== $amount,
                );

                if ($differs && ! $applyToSize) {
                    throw new InvalidArgumentException(
                        "Facilities of size \"{$size}\" must have the same price per rate. ".
                        'Use the existing prices, or tick the option to change every price of this size.',
                    );
                }

                $writeProductRates = $differs && $applyToSize;
            } elseif (
                $facility !== null
                && $facility->facility_product_id !== null
                && (string) $facility->facility_size === $size
                && (int) $facility->facility_type_id === (int) $type->facility_type_id
            ) {
                // Only facility of its size: its own product can be edited freely.
                $product = $facility->facilityProduct;
                $this->fillProductCapacity($product, $isRoom, $min, $max);
                $product->save();
                $writeProductRates = true;
            } else {
                $code = Str::upper(trim((string) ($data['product_code'] ?? '')));

                if ($code === '') {
                    $code = $this->uniqueProductCode($this->slugCode($type->facility_type.'_'.$size));
                } elseif (! preg_match('/^[A-Z0-9_]{2,50}$/', $code)) {
                    throw new InvalidArgumentException('Product code must be 2-50 characters of A-Z, 0-9 and underscores.');
                } elseif (FacilityProduct::query()->where('product_code', $code)->exists()) {
                    throw new InvalidArgumentException("Product code {$code} is already used by another product.");
                }

                $product = new FacilityProduct([
                    'product_code' => $code,
                    'facility_type_id' => $type->facility_type_id,
                    'display_name' => $size,
                    'size_label' => $size,
                    'schedule_policy' => $isRoom ? FacilitySchedulePolicy::Overnight : FacilitySchedulePolicy::DatedSlots,
                    'capacity_policy' => $isRoom ? FacilityCapacityPolicy::Strict : FacilityCapacityPolicy::RecommendedInformational,
                    'is_active' => true,
                ]);
                $this->fillProductCapacity($product, $isRoom, $min, $max);
                $product->save();
                $writeProductRates = true;
            }

            if ($writeProductRates) {
                foreach ($prices as $rateType => $amount) {
                    $rateCode = $this->rateCodeForPriceType($rateType);

                    ProductRate::query()->updateOrCreate(
                        [
                            'facility_product_id' => $product->facility_product_id,
                            'rate_code' => $rateCode,
                        ],
                        [
                            'display_name' => $this->rateDisplayName($rateCode),
                            'amount' => $amount,
                            'is_active' => true,
                        ],
                    );
                }
            }

            $payload = [
                'facility_name' => $name,
                'facility_type_id' => $type->facility_type_id,
                'facility_product_id' => $product->facility_product_id,
                'facility_size' => $size,
                'capacity' => $min.'-'.$max,
                'min_capacity' => $min,
                'max_capacity' => $max,
                'facility_status' => $data['facility_status'],
            ];

            if ($facility === null) {
                $payload['facility_number'] = $facilityNumber;
                $facility = Facility::query()->create($payload);
            } else {
                if (blank($facility->facility_number)) {
                    $payload['facility_number'] = $facilityNumber;
                }

                $facility->update($payload);
            }

            $this->syncFacilityPrices($facility, $prices);

            if ($sizeIsShared && $applyToSize) {
                Facility::query()
                    ->where('facility_type_id', $type->facility_type_id)
                    ->where('facility_size', $size)
                    ->where('facility_id', '!=', $facility->facility_id)
                    ->each(fn (Facility $other) => $this->syncFacilityPrices($other, $prices));
            }

            FacilityAmenity::query()->where('facility_id', $facility->facility_id)->delete();

            foreach ($data['amenities'] ?? [] as $row) {
                FacilityAmenity::query()->create([
                    'facility_id' => $facility->facility_id,
                    'amenity_id' => (int) $row['amenity_id'],
                    'amenity_quantity' => (int) $row['amenity_quantity'],
                ]);
            }

            return $facility->fresh(['facilityType', 'facilityProduct.productRates', 'prices', 'facilityAmenities']);
        }, attempts: 3);
    }

    private function fillProductCapacity(FacilityProduct $product, bool $isRoom, int $min, int $max): void
    {
        if ($isRoom) {
            $product->included_guest_count = $min;
            $product->strict_maximum = $max;
            $product->suggested_minimum = null;
            $product->suggested_maximum = null;

            return;
        }

        $product->suggested_minimum = $min === $max ? null : $min;
        $product->suggested_maximum = $max;
        $product->included_guest_count = null;
        $product->strict_maximum = null;
    }

    /**
     * @param array<string, string|float|int> $submitted
     * @param array<int, string> $expected
     * @return array<string, string>
     */
    private function normalizePrices(array $submitted, array $expected): array
    {
        $prices = [];

        foreach ($expected as $rateType) {
            $amount = $submitted[$rateType] ?? null;

            if (! is_numeric($amount) || (float) $amount <= 0) {
                throw new InvalidArgumentException("A price greater than zero is required for {$rateType}.");
            }

            $prices[$rateType] = number_format((float) $amount, 2, '.', '');
        }

        return $prices;
    }

    /** @param array<string, string> $prices */
    private function syncFacilityPrices(Facility $facility, array $prices): void
    {
        FacilityPrice::query()->where('facility_id', $facility->facility_id)->delete();

        foreach ($prices as $rateType => $amount) {
            FacilityPrice::query()->create([
                'facility_id' => $facility->facility_id,
                'rate_type' => $rateType,
                'facility_price' => $amount,
            ]);
        }
    }

    private function rateCodeForPriceType(string $rateType): FacilityRateCode
    {
        return match ($rateType) {
            'Day Rate', 'Day' => FacilityRateCode::Day,
            'Night Rate', 'Night' => FacilityRateCode::Night,
            'Both' => FacilityRateCode::Both,
            'Overnight' => FacilityRateCode::Overnight,
            default => throw new InvalidArgumentException("Unknown rate type {$rateType}."),
        };
    }

    private function rateDisplayName(FacilityRateCode $rateCode): string
    {
        return match ($rateCode) {
            FacilityRateCode::Day => 'Day',
            FacilityRateCode::Night => 'Night',
            FacilityRateCode::Both => 'Both',
            FacilityRateCode::Overnight => 'Overnight',
            FacilityRateCode::WholeDay => 'Whole Day',
        };
    }

    private function slugCode(string $value): string
    {
        $code = Str::upper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value), '_'));

        return Str::substr($code !== '' ? $code : 'FACILITY', 0, 44);
    }

    private function uniqueProductCode(string $base): string
    {
        $code = $base;
        $suffix = 2;

        while (FacilityProduct::query()->where('product_code', $code)->exists()) {
            $code = $base.'_'.$suffix++;
        }

        return $code;
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
        return collect(explode('_', $product->product_code))
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

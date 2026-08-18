<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $facilityCount = DB::table('tbl_facility')->count();

        if ($facilityCount === 0) {
            return;
        }

        if ($facilityCount !== 146) {
            throw new RuntimeException("Facility product backfill expected 146 facilities; found {$facilityCount}.");
        }

        if (DB::table('tbl_facility_product')->exists() || DB::table('tbl_facility_product_rate')->exists()) {
            throw new RuntimeException('Facility product backfill requires empty normalized product tables.');
        }

        $facilityTypeIds = DB::table('tbl_facility_type')
            ->whereIn('facility_type', ['Cottage', 'Room', 'Function Hall'])
            ->pluck('facility_type_id', 'facility_type');

        if ($facilityTypeIds->count() !== 3) {
            throw new RuntimeException('Facility product backfill requires the Cottage, Room, and Function Hall facility types.');
        }

        $facilityIdsByProduct = [];
        $mappedFacilityIds = [];

        foreach ($this->catalog() as $product) {
            $facilityIds = DB::table('tbl_facility')
                ->where('facility_type_id', $facilityTypeIds[$product['facility_type']])
                ->where('facility_size', $product['facility_size'])
                ->where('capacity', $product['legacy_capacity'])
                ->pluck('facility_id')
                ->map(fn (mixed $facilityId): int => (int) $facilityId)
                ->all();

            if (count($facilityIds) !== $product['facility_count']) {
                throw new RuntimeException(sprintf(
                    'Facility product backfill expected %d %s facilities; found %d.',
                    $product['facility_count'],
                    $product['code'],
                    count($facilityIds),
                ));
            }

            foreach ($facilityIds as $facilityId) {
                $actualRates = DB::table('tbl_facility_price')
                    ->where('facility_id', $facilityId)
                    ->orderBy('rate_type')
                    ->pluck('facility_price', 'rate_type')
                    ->map(fn (mixed $amount): string => number_format((float) $amount, 2, '.', ''))
                    ->all();

                $expectedRates = collect($product['legacy_rates'])
                    ->sortKeys()
                    ->map(fn (int $amount): string => number_format($amount, 2, '.', ''))
                    ->all();

                if ($actualRates !== $expectedRates) {
                    throw new RuntimeException(sprintf(
                        'Facility product backfill found an unexpected legacy rate catalogue for facility ID %d.',
                        $facilityId,
                    ));
                }
            }

            $facilityIdsByProduct[$product['code']] = $facilityIds;
            $mappedFacilityIds = [...$mappedFacilityIds, ...$facilityIds];
        }

        if (count(array_unique($mappedFacilityIds)) !== 146) {
            throw new RuntimeException('Facility product backfill could not map every facility exactly once.');
        }

        DB::transaction(function () use ($facilityIdsByProduct, $facilityTypeIds): void {
            $timestamp = now();

            foreach ($this->catalog() as $product) {
                $facilityProductId = DB::table('tbl_facility_product')->insertGetId([
                    'product_code' => $product['code'],
                    'facility_type_id' => $facilityTypeIds[$product['facility_type']],
                    'display_name' => $product['display_name'],
                    'size_label' => $product['facility_size'],
                    'schedule_policy' => $product['schedule_policy'],
                    'capacity_policy' => $product['capacity_policy'],
                    'suggested_minimum' => $product['suggested_minimum'],
                    'suggested_maximum' => $product['suggested_maximum'],
                    'included_guest_count' => $product['included_guest_count'],
                    'strict_maximum' => $product['strict_maximum'],
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                foreach ($product['rates'] as $rate) {
                    DB::table('tbl_facility_product_rate')->insert([
                        'facility_product_id' => $facilityProductId,
                        'rate_code' => $rate['code'],
                        'display_name' => $rate['display_name'],
                        'amount' => $rate['amount'],
                        'is_active' => true,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }

                DB::table('tbl_facility')
                    ->whereIn('facility_id', $facilityIdsByProduct[$product['code']])
                    ->update(['facility_product_id' => $facilityProductId]);
            }
        });
    }

    public function down(): void
    {
        DB::table('tbl_facility')->update(['facility_product_id' => null]);
        DB::table('tbl_facility_product_rate')->delete();
        DB::table('tbl_facility_product')->delete();
    }

    /**
     * @return array<int, array{
     *     code: string,
     *     facility_type: string,
     *     display_name: string,
     *     facility_size: string,
     *     legacy_capacity: string,
     *     facility_count: int,
     *     schedule_policy: string,
     *     capacity_policy: string,
     *     suggested_minimum: ?int,
     *     suggested_maximum: ?int,
     *     included_guest_count: ?int,
     *     strict_maximum: ?int,
     *     legacy_rates: array<string, int>,
     *     rates: array<int, array{code: string, display_name: string, amount: int}>
     * }>
     */
    private function catalog(): array
    {
        return [
            $this->cottage('COTTAGE_SMALL', 'Small Cottage', '4-6', 55, 4, 6, 300),
            $this->cottage('COTTAGE_MEDIUM', 'Medium Cottage', '8-10', 46, 8, 10, 400),
            $this->cottage('COTTAGE_LARGE', 'Large Cottage', '10-15', 26, 10, 15, 600),
            $this->cottage('COTTAGE_EXTRA_LARGE', 'Extra Large Cottage', '15-25', 5, 15, 25, 900),
            [
                'code' => 'ROOM_STANDARD',
                'facility_type' => 'Room',
                'display_name' => 'Standard Room',
                'facility_size' => 'Standard Room',
                'legacy_capacity' => '4 default / 10 max',
                'facility_count' => 12,
                'schedule_policy' => 'overnight',
                'capacity_policy' => 'strict',
                'suggested_minimum' => null,
                'suggested_maximum' => null,
                'included_guest_count' => 4,
                'strict_maximum' => 10,
                'legacy_rates' => ['Overnight' => 2500],
                'rates' => [
                    ['code' => 'OVERNIGHT', 'display_name' => 'Overnight', 'amount' => 2500],
                ],
            ],
            $this->functionHall('FUNCTION_HALL_1', 'Function Hall 1', '25', 25, 1200),
            $this->functionHall('FUNCTION_HALL_2', 'Function Hall 2', '30', 30, 1500),
        ];
    }

    /**
     * @return array{
     *     code: string,
     *     facility_type: string,
     *     display_name: string,
     *     facility_size: string,
     *     legacy_capacity: string,
     *     facility_count: int,
     *     schedule_policy: string,
     *     capacity_policy: string,
     *     suggested_minimum: int,
     *     suggested_maximum: int,
     *     included_guest_count: null,
     *     strict_maximum: null,
     *     legacy_rates: array<string, int>,
     *     rates: array<int, array{code: string, display_name: string, amount: int}>
     * }
     */
    private function cottage(
        string $code,
        string $displayName,
        string $legacyCapacity,
        int $facilityCount,
        int $suggestedMinimum,
        int $suggestedMaximum,
        int $slotAmount,
    ): array {
        return [
            'code' => $code,
            'facility_type' => 'Cottage',
            'display_name' => $displayName,
            'facility_size' => $displayName,
            'legacy_capacity' => $legacyCapacity,
            'facility_count' => $facilityCount,
            'schedule_policy' => 'dated_slots',
            'capacity_policy' => 'recommended_informational',
            'suggested_minimum' => $suggestedMinimum,
            'suggested_maximum' => $suggestedMaximum,
            'included_guest_count' => null,
            'strict_maximum' => null,
            'legacy_rates' => ['Day Rate' => $slotAmount, 'Night Rate' => $slotAmount],
            'rates' => [
                ['code' => 'DAY', 'display_name' => 'Day', 'amount' => $slotAmount],
                ['code' => 'NIGHT', 'display_name' => 'Night', 'amount' => $slotAmount],
                ['code' => 'BOTH', 'display_name' => 'Both', 'amount' => $slotAmount * 2],
            ],
        ];
    }

    /**
     * @return array{
     *     code: string,
     *     facility_type: string,
     *     display_name: string,
     *     facility_size: string,
     *     legacy_capacity: string,
     *     facility_count: int,
     *     schedule_policy: string,
     *     capacity_policy: string,
     *     suggested_minimum: null,
     *     suggested_maximum: int,
     *     included_guest_count: null,
     *     strict_maximum: null,
     *     legacy_rates: array<string, int>,
     *     rates: array<int, array{code: string, display_name: string, amount: int}>
     * }
     */
    private function functionHall(
        string $code,
        string $displayName,
        string $legacyCapacity,
        int $suggestedMaximum,
        int $amount,
    ): array {
        return [
            'code' => $code,
            'facility_type' => 'Function Hall',
            'display_name' => $displayName,
            'facility_size' => $displayName,
            'legacy_capacity' => $legacyCapacity,
            'facility_count' => 1,
            'schedule_policy' => 'whole_calendar_day',
            'capacity_policy' => 'recommended_informational',
            'suggested_minimum' => null,
            'suggested_maximum' => $suggestedMaximum,
            'included_guest_count' => null,
            'strict_maximum' => null,
            'legacy_rates' => ['Day Rate' => $amount, 'Night Rate' => $amount],
            'rates' => [
                ['code' => 'WHOLE_DAY', 'display_name' => 'Whole Day', 'amount' => $amount],
            ],
        ];
    }
};

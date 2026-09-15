<?php

namespace Database\Seeders;

use App\FacilityCapacityPolicy;
use App\FacilityProductCode;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class FacilityProductSeeder extends Seeder
{
    public function run(): void
    {
        $facilityTypeIds = DB::table('tbl_facility_type')
            ->whereIn('facility_type', ['Cottage', 'Room', 'Function Hall'])
            ->pluck('facility_type_id', 'facility_type');

        if ($facilityTypeIds->count() !== 3) {
            throw new LogicException('The approved facility types must be seeded before facility products.');
        }

        foreach ($this->catalog() as $product) {
            DB::table('tbl_facility_product')->updateOrInsert(
                ['product_code' => $product['code']->value],
                [
                    'facility_type_id' => $facilityTypeIds[$product['facility_type']],
                    'display_name' => $product['display_name'],
                    'size_label' => $product['size_label'],
                    'schedule_policy' => $product['schedule_policy']->value,
                    'capacity_policy' => $product['capacity_policy']->value,
                    'suggested_minimum' => $product['suggested_minimum'],
                    'suggested_maximum' => $product['suggested_maximum'],
                    'included_guest_count' => $product['included_guest_count'],
                    'strict_maximum' => $product['strict_maximum'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            $facilityProductId = DB::table('tbl_facility_product')
                ->where('product_code', $product['code']->value)
                ->value('facility_product_id');

            foreach ($product['rates'] as $rate) {
                DB::table('tbl_facility_product_rate')->updateOrInsert(
                    [
                        'facility_product_id' => $facilityProductId,
                        'rate_code' => $rate['code']->value,
                    ],
                    [
                        'display_name' => $rate['display_name'],
                        'amount' => $rate['amount'],
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    /**
     * @return array<int, array{
     *     code: FacilityProductCode,
     *     facility_type: string,
     *     display_name: string,
     *     size_label: string,
     *     schedule_policy: FacilitySchedulePolicy,
     *     capacity_policy: FacilityCapacityPolicy,
     *     suggested_minimum: ?int,
     *     suggested_maximum: ?int,
     *     included_guest_count: ?int,
     *     strict_maximum: ?int,
     *     rates: array<int, array{code: FacilityRateCode, display_name: string, amount: int}>
     * }>
     */
    private function catalog(): array
    {
        return [
            $this->cottage(FacilityProductCode::CottageSmall, 'Small Cottage', 4, 6, 300),
            $this->cottage(FacilityProductCode::CottageMedium, 'Medium Cottage', 8, 10, 400),
            $this->cottage(FacilityProductCode::CottageLarge, 'Large Cottage', 10, 15, 600),
            $this->cottage(FacilityProductCode::CottageExtraLarge, 'Extra Large Cottage', 15, 25, 900),
            [
                'code' => FacilityProductCode::RoomStandard,
                'facility_type' => 'Room',
                'display_name' => 'Standard Room',
                'size_label' => 'Standard Room',
                'schedule_policy' => FacilitySchedulePolicy::Overnight,
                'capacity_policy' => FacilityCapacityPolicy::Strict,
                'suggested_minimum' => null,
                'suggested_maximum' => null,
                'included_guest_count' => 4,
                'strict_maximum' => 10,
                'rates' => [
                    ['code' => FacilityRateCode::Overnight, 'display_name' => 'Overnight', 'amount' => 2500],
                ],
            ],
            $this->functionHall(FacilityProductCode::FunctionHall1, 'Function Hall 1', 25, 1200),
            $this->functionHall(FacilityProductCode::FunctionHall2, 'Function Hall 2', 30, 1500),
        ];
    }

    /**
     * @return array{
     *     code: FacilityProductCode,
     *     facility_type: string,
     *     display_name: string,
     *     size_label: string,
     *     schedule_policy: FacilitySchedulePolicy,
     *     capacity_policy: FacilityCapacityPolicy,
     *     suggested_minimum: int,
     *     suggested_maximum: int,
     *     included_guest_count: null,
     *     strict_maximum: null,
     *     rates: array<int, array{code: FacilityRateCode, display_name: string, amount: int}>
     * }
     */
    private function cottage(
        FacilityProductCode $code,
        string $displayName,
        int $suggestedMinimum,
        int $suggestedMaximum,
        int $slotAmount,
    ): array {
        return [
            'code' => $code,
            'facility_type' => 'Cottage',
            'display_name' => $displayName,
            'size_label' => $displayName,
            'schedule_policy' => FacilitySchedulePolicy::DatedSlots,
            'capacity_policy' => FacilityCapacityPolicy::RecommendedInformational,
            'suggested_minimum' => $suggestedMinimum,
            'suggested_maximum' => $suggestedMaximum,
            'included_guest_count' => null,
            'strict_maximum' => null,
            'rates' => [
                ['code' => FacilityRateCode::Day, 'display_name' => 'Day', 'amount' => $slotAmount],
                ['code' => FacilityRateCode::Night, 'display_name' => 'Night', 'amount' => $slotAmount],
                ['code' => FacilityRateCode::Both, 'display_name' => 'Both', 'amount' => $slotAmount * 2],
            ],
        ];
    }

    /**
     * @return array{
     *     code: FacilityProductCode,
     *     facility_type: string,
     *     display_name: string,
     *     size_label: string,
     *     schedule_policy: FacilitySchedulePolicy,
     *     capacity_policy: FacilityCapacityPolicy,
     *     suggested_minimum: null,
     *     suggested_maximum: int,
     *     included_guest_count: null,
     *     strict_maximum: null,
     *     rates: array<int, array{code: FacilityRateCode, display_name: string, amount: int}>
     * }
     */
    private function functionHall(
        FacilityProductCode $code,
        string $displayName,
        int $suggestedMaximum,
        int $amount,
    ): array {
        return [
            'code' => $code,
            'facility_type' => 'Function Hall',
            'display_name' => $displayName,
            'size_label' => $displayName,
            'schedule_policy' => FacilitySchedulePolicy::WholeCalendarDay,
            'capacity_policy' => FacilityCapacityPolicy::RecommendedInformational,
            'suggested_minimum' => null,
            'suggested_maximum' => $suggestedMaximum,
            'included_guest_count' => null,
            'strict_maximum' => null,
            'rates' => [
                ['code' => FacilityRateCode::WholeDay, 'display_name' => 'Whole Day', 'amount' => $amount],
            ],
        ];
    }
}

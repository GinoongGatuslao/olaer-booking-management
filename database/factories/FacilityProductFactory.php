<?php

namespace Database\Factories;

use App\FacilityCapacityPolicy;
use App\FacilityProductCode;
use App\FacilitySchedulePolicy;
use App\Models\FacilityProduct;
use App\Models\FacilityType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacilityProduct>
 */
class FacilityProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_code' => FacilityProductCode::CottageSmall,
            'facility_type_id' => FacilityType::query()->firstOrCreate([
                'facility_type' => 'Cottage',
            ])->facility_type_id,
            'display_name' => 'Small Cottage',
            'size_label' => 'Small Cottage',
            'schedule_policy' => FacilitySchedulePolicy::DatedSlots,
            'capacity_policy' => FacilityCapacityPolicy::RecommendedInformational,
            'suggested_minimum' => 4,
            'suggested_maximum' => 6,
            'included_guest_count' => null,
            'strict_maximum' => null,
            'is_active' => true,
        ];
    }
}

<?php

namespace Database\Factories;

use App\FacilityRateCode;
use App\Models\FacilityProduct;
use App\Models\ProductRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductRate>
 */
class ProductRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_product_id' => FacilityProduct::factory(),
            'rate_code' => FacilityRateCode::Day,
            'display_name' => 'Day',
            'amount' => fake()->randomFloat(2, 100, 5000),
            'is_active' => true,
        ];
    }
}

<?php

namespace Tests\Feature;

use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\FacilityType;
use App\Services\FacilityManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FacilityCreationWithProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_facility_with_a_new_name_creates_a_custom_product_and_rates(): void
    {
        $type = FacilityType::query()->create(['facility_type' => 'Cottage']);

        $facility = app(FacilityManagementService::class)->saveFacility(null, $this->payload($type, [
            'facility_name' => 'C1200-001',
            'facility_size' => 'Jumbo Cottage',
            'min_capacity' => 30,
            'max_capacity' => 40,
            'prices' => ['Day Rate' => 1200, 'Night Rate' => 1300, 'Both' => 1300],
        ]));

        $product = $facility->facilityProduct;

        $this->assertSame('COTTAGE_JUMBO', $product->product_code);
        $this->assertSame(FacilitySchedulePolicy::DatedSlots, $product->schedule_policy);
        $this->assertSame(40, $product->suggested_maximum);
        $this->assertSame('C1200-001', $facility->facility_number);
        $this->assertEqualsCanonicalizing(
            ['DAY', 'NIGHT', 'BOTH'],
            $product->productRates->map(fn ($rate) => $rate->rate_code->value)->all(),
        );
        $this->assertDatabaseHas('tbl_facility_price', [
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Both',
            'facility_price' => '1300.00',
        ]);
    }

    public function test_function_hall_gets_day_night_and_both_dated_slot_rates(): void
    {
        $type = FacilityType::query()->create(['facility_type' => 'Function Hall']);

        $facility = app(FacilityManagementService::class)->saveFacility(null, $this->payload($type, [
            'facility_name' => 'FH-3',
            'facility_size' => 'Function Hall 3',
            'min_capacity' => 50,
            'max_capacity' => 50,
            'prices' => ['Day Rate' => 2000, 'Night Rate' => 2000, 'Both' => 4000],
        ]));

        $product = $facility->facilityProduct;

        $this->assertSame('FUNCTION_HALL_3', $product->product_code);
        $this->assertSame(FacilitySchedulePolicy::DatedSlots, $product->schedule_policy);
        $this->assertSame('4000.00', $product->productRates->firstWhere('rate_code', FacilityRateCode::Both)->amount);
    }

    public function test_a_second_facility_of_an_existing_size_reuses_the_product(): void
    {
        $type = FacilityType::query()->create(['facility_type' => 'Cottage']);
        $service = app(FacilityManagementService::class);

        $first = $service->saveFacility(null, $this->payload($type));
        $second = $service->saveFacility(null, $this->payload($type, ['facility_name' => 'C-002']));

        $this->assertSame($first->facility_product_id, $second->facility_product_id);
        $this->assertSame(1, FacilityProduct::query()->count());
    }

    public function test_different_prices_for_an_existing_size_are_rejected_unless_applied_to_the_size(): void
    {
        $type = FacilityType::query()->create(['facility_type' => 'Cottage']);
        $service = app(FacilityManagementService::class);
        $first = $service->saveFacility(null, $this->payload($type));

        try {
            $service->saveFacility(null, $this->payload($type, [
                'facility_name' => 'C-002',
                'prices' => ['Day Rate' => 350, 'Night Rate' => 400, 'Both' => 400],
            ]));
            $this->fail('Expected different prices for the same size to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('same price', $exception->getMessage());
        }

        $this->assertSame(1, Facility::query()->count());

        $second = $service->saveFacility(null, $this->payload($type, [
            'facility_name' => 'C-002',
            'prices' => ['Day Rate' => 350, 'Night Rate' => 400, 'Both' => 400],
            'apply_to_size' => true,
        ]));

        $this->assertDatabaseHas('tbl_facility_price', [
            'facility_id' => $first->facility_id,
            'rate_type' => 'Day Rate',
            'facility_price' => '350.00',
        ]);
        $this->assertSame(
            '350.00',
            $second->facilityProduct->productRates->firstWhere('rate_code', FacilityRateCode::Day)->amount,
        );
    }

    public function test_system_generates_unique_product_codes(): void
    {
        $type = FacilityType::query()->create(['facility_type' => 'Cottage']);
        $service = app(FacilityManagementService::class);

        $service->saveFacility(null, $this->payload($type, ['facility_size' => 'Jumbo Cottage']));
        $service->saveFacility(null, $this->payload($type, ['facility_name' => 'C-009', 'facility_size' => 'Jumbo  cottage!']));

        $this->assertEqualsCanonicalizing(
            ['COTTAGE_JUMBO', 'COTTAGE_JUMBO_2'],
            FacilityProduct::query()->pluck('product_code')->all(),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function payload(FacilityType $type, array $overrides = []): array
    {
        return array_merge([
            'facility_name' => 'C-001',
            'facility_type_id' => $type->facility_type_id,
            'facility_size' => 'Small Cottage',
            'min_capacity' => 4,
            'max_capacity' => 6,
            'facility_status' => 'Available',
            'prices' => ['Day Rate' => 300, 'Night Rate' => 400, 'Both' => 400],
            'amenities' => [],
        ], $overrides);
    }
}

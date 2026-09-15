<?php

namespace Tests\Feature;

use App\FacilityCapacityPolicy;
use App\FacilityProductCode;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\ActivityLog;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\FacilityType;
use App\Models\ProductRate;
use App\Services\AuditObserverRegistry;
use Database\Seeders\AmenitySeeder;
use Database\Seeders\FacilityProductSeeder;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\FacilityTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class FacilityProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCatalog();
    }

    public function test_approved_product_and_rate_catalog_is_seeded_idempotently(): void
    {
        $this->seedCatalog();

        $this->assertSame(7, FacilityProduct::query()->count());
        $this->assertSame(15, ProductRate::query()->count());

        $expectedCodes = collect(FacilityProductCode::cases())
            ->map(fn (FacilityProductCode $code): string => $code->value)
            ->sort()
            ->values()
            ->all();

        $actualCodes = DB::table('tbl_facility_product')
            ->pluck('product_code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expectedCodes, $actualCodes);
        $this->assertSame(7, FacilityProduct::query()->distinct()->count('product_code'));

        $cottageProducts = FacilityProduct::query()
            ->with('productRates')
            ->whereIn('product_code', [
                FacilityProductCode::CottageSmall->value,
                FacilityProductCode::CottageMedium->value,
                FacilityProductCode::CottageLarge->value,
                FacilityProductCode::CottageExtraLarge->value,
            ])
            ->get()
            ->keyBy(fn (FacilityProduct $product): string => (string) $product->getRawOriginal('product_code'));

        $expectedCottages = [
            FacilityProductCode::CottageSmall->value => [4, 6, 300],
            FacilityProductCode::CottageMedium->value => [8, 10, 400],
            FacilityProductCode::CottageLarge->value => [10, 15, 600],
            FacilityProductCode::CottageExtraLarge->value => [15, 25, 900],
        ];

        foreach ($expectedCottages as $code => [$minimum, $maximum, $slotAmount]) {
            $product = $cottageProducts[$code];
            $rates = $product->productRates->keyBy(
                fn (ProductRate $rate): string => (string) $rate->getRawOriginal('rate_code'),
            );

            $this->assertSame(FacilitySchedulePolicy::DatedSlots, $product->schedule_policy);
            $this->assertSame(FacilityCapacityPolicy::RecommendedInformational, $product->capacity_policy);
            $this->assertSame($minimum, $product->suggested_minimum);
            $this->assertSame($maximum, $product->suggested_maximum);
            $this->assertSame(
                (float) $rates[FacilityRateCode::Day->value]->amount
                    + (float) $rates[FacilityRateCode::Night->value]->amount,
                (float) $rates[FacilityRateCode::Both->value]->amount,
            );
            $this->assertSame((float) $slotAmount, (float) $rates[FacilityRateCode::Day->value]->amount);
            $this->assertCount(3, $rates);
        }

        $room = FacilityProduct::query()
            ->with('productRates')
            ->where('product_code', FacilityProductCode::RoomStandard->value)
            ->firstOrFail();

        $this->assertSame(4, $room->included_guest_count);
        $this->assertSame(10, $room->strict_maximum);
        $this->assertSame(FacilitySchedulePolicy::Overnight, $room->schedule_policy);
        $this->assertSame(FacilityCapacityPolicy::Strict, $room->capacity_policy);
        $this->assertSame([FacilityRateCode::Overnight], $room->productRates->pluck('rate_code')->all());
        $this->assertSame(2500.0, (float) $room->productRates->sole()->amount);

        $expectedFunctionHalls = [
            FacilityProductCode::FunctionHall1->value => [25, 1200],
            FacilityProductCode::FunctionHall2->value => [30, 1500],
        ];

        foreach ($expectedFunctionHalls as $code => [$maximum, $amount]) {
            $product = FacilityProduct::query()
                ->with('productRates')
                ->where('product_code', $code)
                ->firstOrFail();

            $this->assertSame(FacilitySchedulePolicy::WholeCalendarDay, $product->schedule_policy);
            $this->assertSame(FacilityCapacityPolicy::RecommendedInformational, $product->capacity_policy);
            $this->assertSame($maximum, $product->suggested_maximum);
            $this->assertSame([FacilityRateCode::WholeDay], $product->productRates->pluck('rate_code')->all());
            $this->assertSame((float) $amount, (float) $product->productRates->sole()->amount);
        }

        $this->assertSame(12, ProductRate::query()->whereHas(
            'facilityProduct',
            fn ($query) => $query->where('facility_type_id', $this->facilityTypeId('Cottage')),
        )->count());
        $this->assertSame(1, ProductRate::query()->whereHas(
            'facilityProduct',
            fn ($query) => $query->where('facility_type_id', $this->facilityTypeId('Room')),
        )->count());
        $this->assertSame(2, ProductRate::query()->whereHas(
            'facilityProduct',
            fn ($query) => $query->where('facility_type_id', $this->facilityTypeId('Function Hall')),
        )->count());
    }

    public function test_all_physical_facilities_are_mapped_and_relationships_are_available(): void
    {
        $expectedCounts = [
            FacilityProductCode::CottageSmall->value => 55,
            FacilityProductCode::CottageMedium->value => 46,
            FacilityProductCode::CottageLarge->value => 26,
            FacilityProductCode::CottageExtraLarge->value => 5,
            FacilityProductCode::RoomStandard->value => 12,
            FacilityProductCode::FunctionHall1->value => 1,
            FacilityProductCode::FunctionHall2->value => 1,
        ];

        $this->assertSame(146, Facility::query()->count());
        $this->assertSame(146, Facility::query()->whereNotNull('facility_product_id')->count());

        foreach ($expectedCounts as $code => $count) {
            $product = FacilityProduct::query()
                ->with(['facilityType', 'facilities', 'productRates'])
                ->where('product_code', $code)
                ->firstOrFail();

            $this->assertCount($count, $product->facilities);
            $this->assertNotEmpty($product->productRates);
            $this->assertNotNull($product->facilityType);
        }

        $facility = Facility::query()
            ->with(['facilityProduct.productRates', 'prices'])
            ->firstOrFail();

        $this->assertNotNull($facility->facilityProduct);
        $this->assertNotEmpty($facility->facilityProduct->productRates);
        $this->assertNotEmpty($facility->prices);
        $this->assertSame(280, DB::table('tbl_facility_price')->count());
    }

    public function test_nullable_product_relationship_keeps_simplified_facility_fixtures_supported(): void
    {
        $facility = Facility::query()->create([
            'facility_name' => 'Simplified Fixture',
            'facility_type_id' => $this->facilityTypeId('Cottage'),
            'facility_product_id' => null,
            'facility_size' => 'Standard',
            'facility_status' => 'Available',
            'capacity' => '4',
        ]);

        $this->assertModelExists($facility);
        $this->assertNull($facility->facility_product_id);
        $this->assertNull($facility->facilityProduct);
    }

    public function test_duplicate_product_codes_are_rejected(): void
    {
        $this->expectException(QueryException::class);

        FacilityProduct::query()->create([
            'product_code' => FacilityProductCode::CottageSmall,
            'facility_type_id' => $this->facilityTypeId('Cottage'),
            'display_name' => 'Duplicate Small Cottage',
            'size_label' => 'Duplicate',
            'schedule_policy' => FacilitySchedulePolicy::DatedSlots,
            'capacity_policy' => FacilityCapacityPolicy::RecommendedInformational,
            'is_active' => true,
        ]);
    }

    public function test_duplicate_rate_codes_within_a_product_are_rejected(): void
    {
        $product = FacilityProduct::query()
            ->where('product_code', FacilityProductCode::CottageSmall->value)
            ->firstOrFail();

        $this->expectException(QueryException::class);

        ProductRate::query()->create([
            'facility_product_id' => $product->facility_product_id,
            'rate_code' => FacilityRateCode::Day,
            'display_name' => 'Duplicate Day',
            'amount' => 300,
            'is_active' => true,
        ]);
    }

    public function test_product_and_rate_codes_cannot_be_changed(): void
    {
        $product = FacilityProduct::query()->firstOrFail();
        $product->setAttribute('product_code', FacilityProductCode::RoomStandard->value);

        try {
            $product->save();
            $this->fail('Changing a facility product code should fail.');
        } catch (LogicException) {
            $this->assertSame(FacilityProductCode::RoomStandard, $product->product_code);
        }

        $rate = ProductRate::query()->firstOrFail();
        $rate->setAttribute('rate_code', FacilityRateCode::WholeDay->value);

        $this->expectException(LogicException::class);
        $rate->save();
    }

    public function test_facility_product_foreign_key_rejects_unknown_products(): void
    {
        $this->expectException(QueryException::class);

        Facility::query()->create([
            'facility_name' => 'Invalid Product Fixture',
            'facility_type_id' => $this->facilityTypeId('Cottage'),
            'facility_product_id' => 999999,
            'facility_size' => 'Standard',
            'facility_status' => 'Available',
            'capacity' => '4',
        ]);
    }

    public function test_facility_product_foreign_key_prevents_deleting_a_mapped_product(): void
    {
        $product = FacilityProduct::query()
            ->where('product_code', FacilityProductCode::CottageSmall->value)
            ->firstOrFail();

        $this->expectException(QueryException::class);
        $product->delete();
    }

    public function test_normalized_product_models_use_the_existing_audit_integration(): void
    {
        $auditedModels = app(AuditObserverRegistry::class)->models();

        $this->assertContains(FacilityProduct::class, $auditedModels);
        $this->assertContains(ProductRate::class, $auditedModels);

        $product = FacilityProduct::query()->firstOrFail();
        $product->update(['display_name' => 'Audited Product Name']);

        $rate = $product->productRates()->firstOrFail();
        $rate->update(['amount' => 301]);

        $this->assertTrue(ActivityLog::query()
            ->where('subject_type', FacilityProduct::class)
            ->where('subject_id', $product->facility_product_id)
            ->where('action', 'Updated')
            ->exists());

        $this->assertTrue(ActivityLog::query()
            ->where('subject_type', ProductRate::class)
            ->where('subject_id', $rate->facility_product_rate_id)
            ->where('action', 'Updated')
            ->exists());
    }

    public function test_backfill_uses_master_data_instead_of_numbered_facility_names(): void
    {
        $migration = require database_path(
            'migrations/2026_08_18_093531_backfill_facility_products_and_rates.php',
        );

        $migration->down();

        Facility::query()
            ->orderBy('facility_id')
            ->each(function (Facility $facility): void {
                DB::table('tbl_facility')
                    ->where('facility_id', $facility->facility_id)
                    ->update(['facility_name' => 'Legacy Unit '.$facility->facility_id]);
            });

        $migration->up();

        $this->assertSame(7, FacilityProduct::query()->count());
        $this->assertSame(15, ProductRate::query()->count());
        $this->assertSame(146, Facility::query()->whereNotNull('facility_product_id')->count());
        $this->assertSame(55, $this->facilityCount(FacilityProductCode::CottageSmall));
        $this->assertSame(46, $this->facilityCount(FacilityProductCode::CottageMedium));
        $this->assertSame(26, $this->facilityCount(FacilityProductCode::CottageLarge));
        $this->assertSame(5, $this->facilityCount(FacilityProductCode::CottageExtraLarge));
        $this->assertSame(12, $this->facilityCount(FacilityProductCode::RoomStandard));
        $this->assertSame(1, $this->facilityCount(FacilityProductCode::FunctionHall1));
        $this->assertSame(1, $this->facilityCount(FacilityProductCode::FunctionHall2));
        $this->assertSame(280, DB::table('tbl_facility_price')->count());
    }

    public function test_backfill_stops_before_writing_when_the_legacy_catalog_does_not_match(): void
    {
        $migration = require database_path(
            'migrations/2026_08_18_093531_backfill_facility_products_and_rates.php',
        );

        $migration->down();

        DB::table('tbl_facility')
            ->where('facility_size', 'Small Cottage')
            ->limit(1)
            ->update(['capacity' => 'unexpected']);

        try {
            $migration->up();
            $this->fail('A mismatched legacy catalog should stop the backfill.');
        } catch (RuntimeException) {
            $this->assertSame(0, FacilityProduct::query()->count());
            $this->assertSame(0, ProductRate::query()->count());
            $this->assertSame(0, Facility::query()->whereNotNull('facility_product_id')->count());
        }
    }

    private function seedCatalog(): void
    {
        $this->seed([
            FacilityTypeSeeder::class,
            FacilityProductSeeder::class,
            AmenitySeeder::class,
            FacilitySeeder::class,
        ]);
    }

    private function facilityTypeId(string $facilityType): int
    {
        return (int) FacilityType::query()
            ->where('facility_type', $facilityType)
            ->value('facility_type_id');
    }

    private function facilityCount(FacilityProductCode $code): int
    {
        return Facility::query()
            ->whereHas(
                'facilityProduct',
                fn ($query) => $query->where('product_code', $code->value),
            )
            ->count();
    }
}

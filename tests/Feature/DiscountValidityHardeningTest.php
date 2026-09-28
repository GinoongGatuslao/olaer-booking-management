<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Services\DiscountResolverService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscountValidityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_undated_active_discount_is_not_automatically_applied(): void
    {
        $this->seed(DatabaseSeeder::class);

        $room = Facility::query()
            ->whereHas('facilityType', fn ($query) => $query->where('facility_type', 'Room'))
            ->firstOrFail();

        $undatedId = DB::table('tbl_discount')->insertGetId([
            'discount_name' => 'Undated 50%',
            'discount_amount' => 0.50,
            'app_to_adult' => false,
            'app_to_children' => false,
            'app_to_SC_PWD' => false,
            'app_to_cottage' => false,
            'app_to_room' => true,
            'app_to_function_hall' => false,
            'discount_start' => null,
            'discount_end' => null,
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $datedId = DB::table('tbl_discount')->insertGetId([
            'discount_name' => 'Valid 10%',
            'discount_amount' => 0.10,
            'app_to_adult' => false,
            'app_to_children' => false,
            'app_to_SC_PWD' => false,
            'app_to_cottage' => false,
            'app_to_room' => true,
            'app_to_function_hall' => false,
            'discount_start' => now()->subDay()->toDateString(),
            'discount_end' => now()->addDay()->toDateString(),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolver = app(DiscountResolverService::class);

        $resolved = $resolver->resolveForFacility(
            (int) $room->facility_id,
            today(),
        );

        $this->assertNotNull($resolved);
        $this->assertSame($datedId, (int) $resolved->discount_id);
        $this->assertNotSame($undatedId, (int) $resolved->discount_id);

        $undated = \App\Models\Discount::query()->findOrFail($undatedId);
        $this->assertFalse(
            $resolver->appliesToFacilityOn($undated, $room, today()),
        );
    }

    public function test_highest_dated_facility_discount_wins_automatically(): void
    {
        $this->seed(DatabaseSeeder::class);

        $room = Facility::query()
            ->whereHas('facilityType', fn ($query) => $query->where('facility_type', 'Room'))
            ->firstOrFail();

        foreach ([
            ['name' => 'Valid 10%', 'rate' => 0.10],
            ['name' => 'Valid 20%', 'rate' => 0.20],
        ] as $discount) {
            DB::table('tbl_discount')->insert([
                'discount_name' => $discount['name'],
                'discount_amount' => $discount['rate'],
                'app_to_adult' => false,
                'app_to_children' => false,
                'app_to_SC_PWD' => false,
                'app_to_cottage' => false,
                'app_to_room' => true,
                'app_to_function_hall' => false,
                'discount_start' => now()->subDay()->toDateString(),
                'discount_end' => now()->addDay()->toDateString(),
                'status' => 'Active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $resolved = app(DiscountResolverService::class)->resolveForFacility(
            (int) $room->facility_id,
            today(),
        );

        $this->assertNotNull($resolved);
        $this->assertSame('Valid 20%', $resolved->discount_name);
        $this->assertSame(0.20, (float) $resolved->discount_amount);
    }

}

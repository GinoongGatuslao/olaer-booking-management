<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\FacilityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFacilityPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_facility_table_uses_laravel_pagination(): void
    {
        $facilityType = FacilityType::query()->create([
            'facility_type' => 'Cottage',
        ]);

        foreach (range(1, 11) as $number) {
            Facility::query()->create([
                'facility_name' => sprintf('Facility %02d', $number),
                'facility_type_id' => $facilityType->facility_type_id,
                'facility_size' => 'Small Cottage',
                'facility_status' => 'Available',
                'capacity' => '4-6',
            ]);
        }

        Livewire::test('admin.facilities.index')
            ->assertSee('Facility 01')
            ->assertDontSee('Facility 11')
            ->call('gotoPage', 2)
            ->assertSee('Facility 11')
            ->assertDontSee('Facility 01');
    }
}

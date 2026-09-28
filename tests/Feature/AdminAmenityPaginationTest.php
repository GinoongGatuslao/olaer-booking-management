<?php

namespace Tests\Feature;

use App\Models\Amenity;
use App\Models\AmenityName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAmenityPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_amenity_table_uses_laravel_pagination(): void
    {
        foreach (range(1, 11) as $number) {
            $amenityName = AmenityName::query()->create([
                'amenity_name' => sprintf('Amenity %02d', $number),
            ]);

            Amenity::query()->create([
                'amenity_name_id' => $amenityName->amenity_name_id,
                'amenity_description' => 'Reusable item',
                'amenity_type' => 'Rentable',
                'amenity_price' => 100,
            ]);
        }

        Livewire::test('admin.amenities.index')
            ->assertSee('Amenity 01')
            ->assertDontSee('Amenity 11')
            ->call('gotoPage', 2)
            ->assertSee('Amenity 11')
            ->assertDontSee('Amenity 01');
    }

    public function test_cancelling_amenity_edit_discards_unsaved_price(): void
    {
        $name = AmenityName::query()->create(['amenity_name' => 'Chair']);
        $amenity = Amenity::query()->create([
            'amenity_name_id' => $name->amenity_name_id,
            'amenity_description' => 'Monoblock',
            'amenity_type' => 'Rentable',
            'amenity_price' => 100,
        ]);

        Livewire::test('admin.amenities.index')
            ->call('startEditing', $amenity->amenity_id)
            ->assertSet('showEditor', true)
            ->set('amenityPrice', '999.00')
            ->call('cancelEdit')
            ->assertSet('showEditor', false)
            ->assertSee('Amenity edit cancelled. No changes were saved.');

        $this->assertEquals(100, $amenity->fresh()->amenity_price);
    }
}

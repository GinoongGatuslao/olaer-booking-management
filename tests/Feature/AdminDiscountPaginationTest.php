<?php

namespace Tests\Feature;

use App\Models\Discount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDiscountPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_discount_table_uses_laravel_pagination(): void
    {
        foreach (range(1, 11) as $number) {
            Discount::query()->create([
                'discount_name' => sprintf('Discount %02d', $number),
                'discount_amount' => 0.10,
                'app_to_adult' => true,
                'app_to_children' => false,
                'app_to_SC_PWD' => false,
                'app_to_cottage' => false,
                'app_to_room' => false,
                'app_to_function_hall' => false,
                'status' => 'Active',
            ]);
        }

        Livewire::test('admin.discounts.index')
            ->assertSee('Discount 01')
            ->assertDontSee('Discount 11')
            ->call('gotoPage', 2)
            ->assertSee('Discount 11')
            ->assertDontSee('Discount 01');
    }
}

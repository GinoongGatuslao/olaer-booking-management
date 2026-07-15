<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Discount;
use App\Models\EntranceFee;
use App\Models\EntranceSlip;
use App\Models\EntranceSlipDetail;
use App\Models\ModeOfPayment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CashierEntranceSlipRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_entrance_slips_component_renders(): void
    {
        Livewire::test('cashier.entrance-slips.index')
            ->assertSee('Entrance slip list');
    }

    public function test_selected_slip_shows_current_discount_breakdown(): void
    {
        ModeOfPayment::query()->create([
            'mode_of_payment' => 'Cash',
        ]);

        $role = Role::query()->create([
            'role_name' => 'Security Guard',
        ]);

        $address = Address::query()->create([
            'province' => 'Laguna',
            'city' => 'Los Banos',
        ]);

        $user = User::query()->create([
            'first_name' => 'Sam',
            'last_name' => 'Guard',
            'username' => 'samguard',
            'password' => 'password',
            'email' => 'sam@example.com',
            'contact_no' => '09123456789',
            'status' => 'Active',
            'address_id' => $address->address_id,
            'role_id' => $role->role_id,
        ]);

        $fee = EntranceFee::query()->create([
            'entrance_fee_name' => 'Adult',
            'entrance_fee_price' => 100,
        ]);

        $discount = Discount::query()->create([
            'discount_name' => 'Senior Citizen',
            'discount_amount' => 0.50,
            'status' => 'Active',
        ]);

        $slip = EntranceSlip::query()->create([
            'no_of_adult' => 2,
            'created_by_user_id' => $user->user_id,
            'date_created' => now()->toDateString(),
            'time_created' => now()->format('H:i:s'),
            'total_price' => 150,
            'amount_due' => 150,
            'status' => 'Unpaid',
        ]);

        EntranceSlipDetail::query()->create([
            'entrance_slip_id' => $slip->entrance_slip_id,
            'entrance_fee_id' => $fee->entrance_fee_id,
            'guest_quantity' => 2,
            'discount_id' => $discount->discount_id,
            'discounted_quantity' => 1,
        ]);

        Livewire::test('cashier.entrance-slips.index')
            ->call('selectSlip', $slip->entrance_slip_id)
            ->assertSee('Entrance fee breakdown')
            ->assertSee('Adult')
            ->assertSee('1')
            ->assertSee('discounted via')
            ->assertSee('Senior Citizen')
            ->assertSee('Total price')
            ->assertSee('Amount due')
            ->assertSee('150.00');
    }
}

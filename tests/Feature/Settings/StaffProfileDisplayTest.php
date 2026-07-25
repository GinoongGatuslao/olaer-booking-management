<?php

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffProfileDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_view_their_olaer_staff_profile(): void
    {
        $cashierRole = Role::query()->create([
            'role_name' => 'Cashier',
        ]);

        $cashier = User::factory()->create([
            'first_name' => 'Kara',
            'middle_name' => 'Dela',
            'last_name' => 'Cruz',
            'username' => 'kara.cashier',
            'email' => 'kara@olaer.test',
            'contact_no' => '09171234567',
            'role_id' => $cashierRole->role_id,
        ]);

        $this->actingAs($cashier)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Kara Dela Cruz')
            ->assertSee('kara.cashier')
            ->assertSee('kara@olaer.test')
            ->assertSee('09171234567')
            ->assertSee('Cashier')
            ->assertSee('Profile changes are managed centrally')
            ->assertDontSee('Delete account');
    }

    public function test_admin_profile_links_to_staff_management(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Manage Staff Accounts');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserManagementRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_management_component_renders(): void
    {
        Livewire::test('admin.users.index')
            ->assertSee('User list');
    }

    public function test_existing_user_can_be_updated_without_changing_password(): void
    {
        $role = Role::query()->create([
            'role_name' => 'Manager',
        ]);

        $address = Address::query()->create([
            'province' => 'Laguna',
            'city' => 'Los Banos',
            'barangay' => 'Batong Malake',
            'purok' => 'Purok 1',
        ]);

        $user = User::query()->create([
            'first_name' => 'Old',
            'last_name' => 'Name',
            'username' => 'oldname',
            'password' => 'old-password',
            'email' => 'old@example.com',
            'contact_no' => '09123456789',
            'status' => 'Active',
            'address_id' => $address->address_id,
            'role_id' => $role->role_id,
        ]);

        $oldPassword = $user->password;

        Livewire::test('admin.users.index')
            ->call('startEditingUser', $user->user_id)
            ->set('firstName', 'New')
            ->set('password', '')
            ->set('passwordConfirmation', '')
            ->call('saveUser')
            ->assertHasNoErrors();

        $user->refresh();

        $this->assertSame('New', $user->first_name);
        $this->assertSame($oldPassword, $user->password);
        $this->assertFalse(Hash::check('', $user->password));
    }
}

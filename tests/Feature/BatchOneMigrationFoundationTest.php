<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BatchOneMigrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_one_schema_and_seed_data_are_consistent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(Role::query()->where('role_name', 'Manager')->exists());

        foreach ([
            'min_capacity',
            'max_capacity',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('tbl_facility', $column));
        }

        foreach ([
            'tbl_transaction_credits',
            'tbl_transaction_credit_allocations',
            'tbl_transaction_adjustments',
            'tbl_inspection_draft_fines',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing {$table}.");
        }

        foreach ([
            'voided_at',
            'voided_by_user_id',
            'void_reason',
            'replacement_entrance_slip_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('tbl_entrance_slip', $column));
        }

        $room = Facility::query()
            ->whereHas('facilityType', fn ($query) => $query->where('facility_type', 'Room'))
            ->firstOrFail();

        $this->assertSame(4, $room->min_capacity);
        $this->assertSame(10, $room->max_capacity);

        $this->assertFalse(
            Facility::query()
                ->whereNull('min_capacity')
                ->orWhereNull('max_capacity')
                ->exists()
        );
    }

    public function test_manager_removal_migration_stops_when_legacy_manager_accounts_exist(): void
    {
        $this->seed(DatabaseSeeder::class);

        $managerRole = Role::query()->create(['role_name' => 'Manager']);
        User::factory()->create([
            'role_id' => $managerRole->role_id,
            'username' => 'legacy-manager',
            'email' => 'legacy-manager@example.test',
        ]);

        $migration = require database_path(
            'migrations/2026_09_24_000001_add_batch_one_migration_foundation.php',
        );

        try {
            $migration->up();
            $this->fail('Migration must stop before silently removing a Manager role that still owns accounts.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(
                'requires explicit reassignment',
                $exception->getMessage(),
            );
            $this->assertStringContainsString(
                'legacy-manager',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('tbl_role', [
            'role_id' => $managerRole->role_id,
            'role_name' => 'Manager',
        ]);
        $this->assertDatabaseHas('tbl_user', [
            'username' => 'legacy-manager',
            'role_id' => $managerRole->role_id,
        ]);
    }

    public function test_manager_removal_migration_deletes_only_unused_legacy_manager_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $managerRole = Role::query()->create(['role_name' => 'Manager']);

        $migration = require database_path(
            'migrations/2026_09_24_000001_add_batch_one_migration_foundation.php',
        );
        $migration->up();

        $this->assertDatabaseMissing('tbl_role', [
            'role_id' => $managerRole->role_id,
            'role_name' => 'Manager',
        ]);
    }


    public function test_user_management_cannot_offer_manager_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::query()->where('username', 'admin')->firstOrFail();
        $this->actingAs($admin);

        Volt::test('admin.users.index')
            ->assertDontSee('Manager');
    }

    public function test_remember_me_queues_recaller_cookie_but_normal_login_does_not(): void
    {
        $this->seed(DatabaseSeeder::class);

        Cookie::unqueue('remember_web');
        Cookie::getQueuedCookies();

        Volt::test('auth.login')
            ->set('username', 'admin')
            ->set('password', 'password')
            ->set('remember', false)
            ->call('login')
            ->assertRedirect(route('admin.dashboard'));

        $recallerName = auth('web')->getRecallerName();

        $this->assertFalse(
            collect(Cookie::getQueuedCookies())->contains(
                fn ($cookie): bool => $cookie->getName() === $recallerName,
            )
        );

        auth('web')->logout();
        Cookie::unqueue($recallerName);

        Volt::test('auth.login')
            ->set('username', 'admin')
            ->set('password', 'password')
            ->set('remember', true)
            ->call('login')
            ->assertRedirect(route('admin.dashboard'));

        $this->assertTrue(
            collect(Cookie::getQueuedCookies())->contains(
                fn ($cookie): bool => $cookie->getName() === $recallerName,
            )
        );
    }
}

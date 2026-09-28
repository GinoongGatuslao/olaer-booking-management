<?php

namespace Tests\Feature;

use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\Address;
use App\Models\Facility;
use App\Models\FacilityScheduleBlock;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationDetail;
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

    public function test_upgrade_stops_on_unmapped_active_schedule_then_backfills_when_repaired(): void
    {
        $this->seed(DatabaseSeeder::class);
        $room = Facility::query()->where('facility_name', 'R-001')->firstOrFail();
        $detail = $this->legacyReservationDetail($room, 'R-UPGRADE-1');
        $migration = require database_path('migrations/2026_09_28_000001_verify_active_facility_schedule_coverage.php');

        try {
            $migration->up();
            $this->fail('An active reservation with no schedule snapshot was silently skipped.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString("reservation detail #{$detail->getKey()}", $exception->getMessage());
            $this->assertStringContainsString('schedule_policy', $exception->getMessage());
        }

        $this->assertSame(0, FacilityScheduleBlock::query()->count());

        $detail->update([
            'schedule_policy' => FacilitySchedulePolicy::Overnight,
            'rate_code' => FacilityRateCode::Overnight,
        ]);
        $migration->up();
        $migration->up();

        $this->assertSame(1, FacilityScheduleBlock::query()
            ->where('reservation_detail_id', $detail->getKey())->count());
    }

    public function test_upgrade_rolls_back_all_new_schedule_blocks_on_historical_collision(): void
    {
        $this->seed(DatabaseSeeder::class);
        $room = Facility::query()->where('facility_name', 'R-001')->firstOrFail();
        $first = $this->legacyReservationDetail($room, 'R-UPGRADE-2', true);
        $second = $this->legacyReservationDetail($room, 'R-UPGRADE-3', true);
        $migration = require database_path('migrations/2026_09_28_000001_verify_active_facility_schedule_coverage.php');

        try {
            $migration->up();
            $this->fail('Conflicting historical reservations were silently assigned the same facility.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString("reservation detail #{$second->getKey()}", $exception->getMessage());
        }

        $this->assertSame(0, FacilityScheduleBlock::query()->count());
        $this->assertNotSame($first->getKey(), $second->getKey());
    }

    private function legacyReservationDetail(Facility $room, string $reference, bool $mapped = false): ReservationDetail
    {
        $address = Address::query()->create([
            'province' => 'South Cotabato',
            'city' => 'General Santos City',
            'barangay' => 'Dadiangas',
        ]);
        $guest = Guest::query()->create([
            'first_name' => 'Legacy',
            'last_name' => 'Guest',
            'contact_no' => '09123456789',
            'email' => strtolower($reference).'@example.test',
            'address_id' => $address->address_id,
        ]);
        $reservation = Reservation::query()->create([
            'r_ref_no' => $reference,
            'guest_id' => $guest->guest_id,
            'reservation_date' => today()->toDateString(),
            'total_price' => '2500.00',
            'amount_due' => '2500.00',
            'status' => 'Active',
        ]);

        return ReservationDetail::query()->create([
            'reservation_id' => $reservation->reservation_id,
            'facility_id' => $room->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => today()->addDays(30)->toDateString(),
            'check_out_date' => today()->addDays(31)->toDateString(),
            'schedule_policy' => $mapped ? FacilitySchedulePolicy::Overnight : null,
            'rate_code' => $mapped ? FacilityRateCode::Overnight : null,
        ]);
    }
}

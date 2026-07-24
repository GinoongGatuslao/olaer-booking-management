<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditObserverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ActivityLogCoverageHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_creation_records_actor_module_and_values(): void
    {
        $actor = $this->createUser(
            'Cashier',
            'audit_booking_actor',
        );

        $this->actingAs($actor);

        $booking = Booking::query()->create([
            'b_ref_no' => 'B-AUDIT-001',
            'guest_id' => $this->createGuest(),
            'booking_date' => '2026-07-20',
            'no_of_extra_guests' => 0,
            'total_guest_count' => 4,
            'total_price' => 1000.00,
            'amount_due' => 0.00,
            'user_id' => $actor->user_id,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'status' => 'Booked',
        ]);

        $log = ActivityLog::query()
            ->where('subject_type', Booking::class)
            ->where(
                'subject_id',
                $booking->booking_id,
            )
            ->where('action', 'Created')
            ->latest('activity_log_id')
            ->firstOrFail();

        $this->assertSame(
            $actor->user_id,
            $log->user_id,
        );

        $this->assertSame(
            'Booking',
            $log->module,
        );

        $this->assertSame(
            'Booking B-AUDIT-001',
            $log->subject_label,
        );

        $this->assertSame(
            'Booked',
            $log->new_values['status'],
        );

        $this->assertSame(
            '1000',
            (string) $log
                ->new_values['total_price'],
        );
    }

    public function test_payment_verification_uses_semantic_action_and_change_values(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'audit_payment_cashier',
        );

        $this->actingAs($cashier);

        $booking = $this->createBooking(
            $cashier,
            'B-AUDIT-PAYMENT',
        );

        $payment = Payment::query()->create([
            'p_ref_no' => 'P-AUDIT-001',
            'booking_id' => $booking->booking_id,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'mode_of_payment_id' =>
                $this->createPaymentMode('GCash'),
            'reference_number' => 'GCASH-AUDIT-001',
            'proof_of_payment_path' =>
                'payments/private-proof.jpg',
            'amount_paid' => 1000.00,
            'date_paid' => '2026-07-20',
            'user_id' => null,
            'payment_status' => 'Pending',
            'verified_by_user_id' => null,
            'verified_at' => null,
            'rejection_reason' => null,
        ]);

        $payment->update([
            'payment_status' => 'Verified',
            'verified_by_user_id' =>
                $cashier->user_id,
            'verified_at' => now(),
        ]);

        $log = ActivityLog::query()
            ->where('subject_type', Payment::class)
            ->where(
                'subject_id',
                $payment->payment_id,
            )
            ->where('action', 'Verified')
            ->latest('activity_log_id')
            ->firstOrFail();

        $this->assertSame(
            'Pending',
            $log->old_values['payment_status'],
        );

        $this->assertSame(
            'Verified',
            $log->new_values['payment_status'],
        );

        $this->assertSame(
            $cashier->user_id,
            $log->user_id,
        );

        $createdLog = ActivityLog::query()
            ->where('subject_type', Payment::class)
            ->where(
                'subject_id',
                $payment->payment_id,
            )
            ->where('action', 'Created')
            ->firstOrFail();

        $this->assertSame(
            '[FILE STORED]',
            $createdLog
                ->new_values[
                    'proof_of_payment_path'
                ],
        );
    }

    public function test_user_password_is_redacted_from_activity_values(): void
    {
        $admin = $this->createUser(
            'Admin',
            'audit_admin_actor',
        );

        $this->actingAs($admin);

        $target = User::query()->create([
            'first_name' => 'Audit',
            'middle_name' => null,
            'last_name' => 'Target',
            'username' => 'audit_target_user',
            'password' => 'SuperSecret123!',
            'email' => 'audit-target@example.test',
            'contact_no' => '09999999998',
            'status' => 'Active',
            'address_id' => $this->createAddress(),
            'role_id' => $this->createRole('Cashier'),
        ]);

        $log = ActivityLog::query()
            ->where('subject_type', User::class)
            ->where(
                'subject_id',
                $target->user_id,
            )
            ->where('action', 'Created')
            ->latest('activity_log_id')
            ->firstOrFail();

        $this->assertSame(
            '[REDACTED]',
            $log->new_values['password'],
        );

        $this->assertStringNotContainsString(
            'SuperSecret123!',
            json_encode(
                $log->new_values,
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function test_activity_log_model_is_not_observed_recursively(): void
    {
        $before = ActivityLog::query()->count();

        ActivityLog::query()->create([
            'user_id' => null,
            'action' => 'System Test',
            'module' => 'Audit',
            'subject_type' => ActivityLog::class,
            'subject_id' => null,
            'subject_label' => 'Manual audit record',
            'description' =>
                'Manual record used to verify non-recursive logging.',
            'old_values' => null,
            'new_values' => [
                'result' => 'ok',
            ],
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => now(),
        ]);

        $this->assertSame(
            $before + 1,
            ActivityLog::query()->count(),
        );

        $this->assertNotContains(
            ActivityLog::class,
            app(AuditObserverRegistry::class)
                ->models(),
        );
    }

    public function test_system_status_transition_has_semantic_action_and_null_actor(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'audit_system_fixture',
        );

        $booking = $this->createBooking(
            $cashier,
            'B-AUDIT-SYSTEM',
        );

        Auth::logout();

        $booking->update([
            'status' => 'Checked-out',
        ]);

        $log = ActivityLog::query()
            ->where('subject_type', Booking::class)
            ->where(
                'subject_id',
                $booking->booking_id,
            )
            ->where('action', 'Checked-out')
            ->latest('activity_log_id')
            ->firstOrFail();

        $this->assertNull($log->user_id);

        $this->assertSame(
            'Booked',
            $log->old_values['status'],
        );

        $this->assertSame(
            'Checked-out',
            $log->new_values['status'],
        );
    }

    private function createBooking(
        User $cashier,
        string $reference,
    ): Booking {
        return Booking::query()->create([
            'b_ref_no' => $reference,
            'guest_id' => $this->createGuest(),
            'booking_date' => '2026-07-20',
            'no_of_extra_guests' => 0,
            'total_guest_count' => 4,
            'total_price' => 1000.00,
            'amount_due' => 0.00,
            'user_id' => $cashier->user_id,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'status' => 'Booked',
        ]);
    }

    private function createUser(
        string $roleName,
        string $username,
    ): User {
        return User::query()->create([
            'first_name' => 'Test',
            'middle_name' => null,
            'last_name' =>
                str_replace(' ', '', $roleName),
            'username' => $username,
            'password' => Hash::make('password'),
            'email' => $username.'@example.test',
            'contact_no' => '09999999999',
            'status' => 'Active',
            'address_id' => $this->createAddress(),
            'role_id' => $this->createRole($roleName),
        ]);
    }

    private function createRole(
        string $roleName,
    ): int {
        $existing = DB::table('tbl_role')
            ->where('role_name', $roleName)
            ->value('role_id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return DB::table('tbl_role')
            ->insertGetId([
                'role_name' => $roleName,
            ]);
    }

    private function createGuest(): int
    {
        return DB::table('tbl_guest')
            ->insertGetId([
                'first_name' => 'Audit',
                'middle_name' => null,
                'last_name' => 'Guest',
                'contact_no' => '09123456789',
                'address_id' => $this->createAddress(),
                'email' =>
                    uniqid('audit_', true)
                    .'@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createAddress(): int
    {
        return DB::table('tbl_address')
            ->insertGetId([
                'purok' => 'Purok 1',
                'province' => 'Sultan Kudarat',
                'city' => 'Tacurong City',
                'barangay' => 'Test Barangay',
            ]);
    }

    private function createPaymentMode(
        string $name,
    ): int {
        $existing = DB::table(
            'tbl_mode_of_payment',
        )
            ->where(
                'mode_of_payment',
                $name,
            )
            ->value('mode_of_payment_id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return DB::table(
            'tbl_mode_of_payment',
        )->insertGetId([
            'mode_of_payment' => $name,
        ]);
    }
}

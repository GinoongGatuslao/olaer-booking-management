<?php

namespace Tests\Feature;

use App\Services\EntranceSlipWorkflowService;
use App\Services\PaymentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class EntranceSlipWorkflowHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_security_guard_can_issue_entrance_slip(): void
    {
        $cashierId = $this->createUser('Cashier');
        $this->createEntranceFees();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Only a Security Guard may issue entrance slips.',
        );

        app(EntranceSlipWorkflowService::class)
            ->issue($this->validIssuePayload($cashierId));
    }

    public function test_security_guard_issues_unpaid_slip_with_correct_details(): void
    {
        $securityId = $this->createUser('Security Guard');
        $this->createEntranceFees();

        $slip = app(EntranceSlipWorkflowService::class)
            ->issue([
                'user_id' => $securityId,
                'adult_count' => 2,
                'children_count' => 1,
                'pwd_sc_count' => 1,
                'male_count' => 2,
                'female_count' => 2,
                'tourist_count' => 1,
                'adult_discount_id' => null,
                'children_discount_id' => null,
                'pwd_sc_discount_id' => null,
                'adult_discounted_quantity' => 0,
                'children_discounted_quantity' => 0,
                'pwd_sc_discounted_quantity' => 0,
            ]);

        $this->assertSame(
            'Unpaid',
            $slip->status,
        );

        $this->assertDatabaseHas('tbl_entrance_slip', [
            'entrance_slip_id' =>
                $slip->entrance_slip_id,
            'no_of_adult' => 2,
            'no_of_children' => 1,
            'no_of_PWD_SC' => 1,
            'no_of_Male' => 2,
            'no_of_Female' => 2,
            'no_of_Tourist' => 1,
            'created_by_user_id' => $securityId,
            'total_price' => 310.00,
            'amount_due' => 310.00,
            'handled_by_user_id' => null,
            'status' => 'Unpaid',
        ]);

        $this->assertDatabaseCount(
            'tbl_entrance_slip_details',
            3,
        );
    }

    public function test_gender_counts_must_equal_total_entrance_guests(): void
    {
        $securityId = $this->createUser('Security Guard');
        $this->createEntranceFees();

        $payload = $this->validIssuePayload($securityId);
        $payload['adult_count'] = 2;
        $payload['male_count'] = 1;
        $payload['female_count'] = 0;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Male and female counts must equal the total entrance guest count.',
        );

        app(EntranceSlipWorkflowService::class)
            ->issue($payload);
    }

    public function test_cashier_records_exact_full_entrance_payment(): void
    {
        $securityId = $this->createUser('Security Guard');
        $cashierId = $this->createUser('Cashier');
        $this->createEntranceFees();
        $cashModeId = $this->createPaymentMode('Cash');

        $slip = app(EntranceSlipWorkflowService::class)
            ->issue($this->validIssuePayload($securityId));

        $payment = app(PaymentWorkflowService::class)
            ->recordCashierPayment([
                'target_type' => 'entrance_slip',
                'target_id' =>
                    $slip->entrance_slip_id,
                'amount_paid' =>
                    (float) $slip->amount_due,
                'mode_of_payment_id' => $cashModeId,
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);

        $this->assertSame(
            'Verified',
            $payment->payment_status,
        );

        $this->assertDatabaseHas('tbl_entrance_slip', [
            'entrance_slip_id' =>
                $slip->entrance_slip_id,
            'amount_due' => 0.00,
            'handled_by_user_id' => $cashierId,
            'status' => 'Paid',
        ]);

        $this->assertDatabaseHas('tbl_payment', [
            'payment_id' => $payment->payment_id,
            'entrance_slip_id' =>
                $slip->entrance_slip_id,
            'amount_paid' =>
                (float) $slip->total_price,
            'payment_status' => 'Verified',
            'verified_by_user_id' => $cashierId,
        ]);
    }

    public function test_entrance_slip_rejects_partial_payment(): void
    {
        $securityId = $this->createUser('Security Guard');
        $cashierId = $this->createUser('Cashier');
        $this->createEntranceFees();

        $slip = app(EntranceSlipWorkflowService::class)
            ->issue($this->validIssuePayload($securityId));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Entrance slips must be paid in full.',
        );

        app(PaymentWorkflowService::class)
            ->recordCashierPayment([
                'target_type' => 'entrance_slip',
                'target_id' =>
                    $slip->entrance_slip_id,
                'amount_paid' => 50.00,
                'mode_of_payment_id' =>
                    $this->createPaymentMode('Cash'),
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);
    }

    public function test_paid_entrance_slip_cannot_be_paid_twice(): void
    {
        $securityId = $this->createUser('Security Guard');
        $cashierId = $this->createUser('Cashier');
        $this->createEntranceFees();
        $cashModeId = $this->createPaymentMode('Cash');

        $slip = app(EntranceSlipWorkflowService::class)
            ->issue($this->validIssuePayload($securityId));

        $service = app(PaymentWorkflowService::class);

        $payload = [
            'target_type' => 'entrance_slip',
            'target_id' => $slip->entrance_slip_id,
            'amount_paid' => (float) $slip->amount_due,
            'mode_of_payment_id' => $cashModeId,
            'reference_number' => '',
            'user_id' => $cashierId,
        ];

        $service->recordCashierPayment($payload);

        $this->expectException(InvalidArgumentException::class);

        $service->recordCashierPayment($payload);
    }

    public function test_unpaid_slip_with_verified_payment_history_is_blocked(): void
    {
        $securityId = $this->createUser('Security Guard');
        $cashierId = $this->createUser('Cashier');
        $this->createEntranceFees();
        $cashModeId = $this->createPaymentMode('Cash');

        $slip = app(EntranceSlipWorkflowService::class)
            ->issue($this->validIssuePayload($securityId));

        $this->insertVerifiedPayment(
            (int) $slip->entrance_slip_id,
            $cashModeId,
            $cashierId,
            (float) $slip->amount_due,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'This entrance slip already has a verified payment.',
        );

        app(PaymentWorkflowService::class)
            ->recordCashierPayment([
                'target_type' => 'entrance_slip',
                'target_id' =>
                    $slip->entrance_slip_id,
                'amount_paid' =>
                    (float) $slip->amount_due,
                'mode_of_payment_id' => $cashModeId,
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);
    }

    private function validIssuePayload(
        int $securityUserId,
    ): array {
        return [
            'user_id' => $securityUserId,
            'adult_count' => 1,
            'children_count' => 0,
            'pwd_sc_count' => 0,
            'male_count' => 1,
            'female_count' => 0,
            'tourist_count' => 0,
            'adult_discount_id' => null,
            'children_discount_id' => null,
            'pwd_sc_discount_id' => null,
            'adult_discounted_quantity' => 0,
            'children_discounted_quantity' => 0,
            'pwd_sc_discounted_quantity' => 0,
        ];
    }

    private function createEntranceFees(): void
    {
        $fees = [
            'Adult' => 100.00,
            'Children' => 80.00,
            'Senior Citizen / PWD' => 30.00,
        ];

        foreach ($fees as $name => $price) {
            DB::table('tbl_entrance_fee')
                ->updateOrInsert(
                    ['entrance_fee_name' => $name],
                    [
                        'entrance_fee_price' => $price,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
        }
    }

    private function createPaymentMode(string $name): int
    {
        $existing = DB::table('tbl_mode_of_payment')
            ->where('mode_of_payment', $name)
            ->value('mode_of_payment_id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return DB::table('tbl_mode_of_payment')
            ->insertGetId([
                'mode_of_payment' => $name,
            ]);
    }

    private function createUser(string $roleName): int
    {
        $roleId = DB::table('tbl_role')
            ->where('role_name', $roleName)
            ->value('role_id');

        if ($roleId === null) {
            $roleId = DB::table('tbl_role')
                ->insertGetId([
                    'role_name' => $roleName,
                ]);
        }

        $username = strtolower(
            str_replace(' ', '_', $roleName),
        ).uniqid();

        return DB::table('tbl_user')->insertGetId([
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
            'role_id' => $roleId,
            'email_verified_at' => now(),
            'remember_token' => null,
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

    private function insertVerifiedPayment(
        int $entranceSlipId,
        int $modeId,
        int $cashierId,
        float $amount,
    ): void {
        $payload = [
            'p_ref_no' => 'P'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'booking_id' => null,
            'reservation_id' => null,
            'entrance_slip_id' => $entranceSlipId,
            'mode_of_payment_id' => $modeId,
            'reference_number' => null,
            'proof_of_payment_path' => null,
            'amount_paid' => $amount,
            'date_paid' => now()->toDateString(),
            'user_id' => $cashierId,
            'payment_status' => 'Verified',
            'verified_by_user_id' => $cashierId,
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (
            Schema::hasColumn(
                'tbl_payment',
                'rejection_reason',
            )
        ) {
            $payload['rejection_reason'] = null;
        }

        DB::table('tbl_payment')->insert($payload);
    }
}

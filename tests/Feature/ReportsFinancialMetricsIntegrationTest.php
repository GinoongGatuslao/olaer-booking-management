<?php

namespace Tests\Feature;

use App\Services\ReportsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReportsFinancialMetricsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_revenue_report_exposes_canonical_metrics_independent_of_table_search(): void
    {
        $cashierId = $this->createUser(
            'Cashier',
            'cashier_report_metrics',
        );

        $cashId = $this->createPaymentMode('Cash');
        $gcashId = $this->createPaymentMode('GCash');

        $bookingId = $this->createBooking(
            'Checked-out',
            0.00,
        );

        $reservationId = $this->createReservation(
            'Paid',
            0.00,
        );

        $this->createPayment(
            targetType: 'booking',
            targetId: $bookingId,
            modeId: $cashId,
            amount: 500.00,
            status: 'Verified',
            datePaid: '2026-07-01',
            userId: $cashierId,
            verifiedByUserId: $cashierId,
        );

        $this->createPayment(
            targetType: 'booking',
            targetId: $bookingId,
            modeId: $gcashId,
            amount: 900.00,
            status: 'Pending',
            datePaid: '2026-07-01',
            userId: null,
            verifiedByUserId: null,
        );

        $this->createPayment(
            targetType: 'reservation',
            targetId: $reservationId,
            modeId: $gcashId,
            amount: 250.00,
            status: 'verified',
            datePaid: '2026-07-02',
            userId: null,
            verifiedByUserId: $cashierId,
        );

        $report = app(ReportsService::class)
            ->revenueReport(
                '2026-07-01',
                '2026-07-02',
                null,
                25,
                'definitely-no-matching-row',
                'reportPage',
                true,
            );

        $this->assertSame(0, $report['count']);
        $this->assertSame(0.00, $report['total']);

        $metrics = $report['financial_metrics'];

        $this->assertSame(
            750.00,
            $metrics['verified_revenue'],
        );

        $this->assertSame(
            2,
            $metrics['verified_payment_count'],
        );

        $this->assertSame(
            500.00,
            $metrics['booking_revenue'],
        );

        $this->assertSame(
            250.00,
            $metrics['reservation_revenue'],
        );

        $this->assertSame(
            500.00,
            $metrics['cash_revenue'],
        );

        $this->assertSame(
            250.00,
            $metrics['gcash_revenue'],
        );

        $this->assertTrue(
            $report['show_outstanding_metrics'],
        );

        $this->assertSame([
            [
                'date' => '2026-07-01',
                'payment_count' => 1,
                'revenue' => 500.00,
            ],
            [
                'date' => '2026-07-02',
                'payment_count' => 1,
                'revenue' => 250.00,
            ],
        ], $report['daily_revenue']);
    }

    public function test_cashier_metrics_include_payments_recorded_or_verified_by_that_cashier(): void
    {
        $cashierOneId = $this->createUser(
            'Cashier',
            'cashier_report_one',
        );

        $cashierTwoId = $this->createUser(
            'Cashier',
            'cashier_report_two',
        );

        $cashId = $this->createPaymentMode('Cash');
        $bookingId = $this->createBooking(
            'Checked-out',
            0.00,
        );

        $this->createPayment(
            targetType: 'booking',
            targetId: $bookingId,
            modeId: $cashId,
            amount: 100.00,
            status: 'Verified',
            datePaid: '2026-07-05',
            userId: $cashierOneId,
            verifiedByUserId: $cashierOneId,
        );

        $this->createPayment(
            targetType: 'booking',
            targetId: $bookingId,
            modeId: $cashId,
            amount: 200.00,
            status: 'Verified',
            datePaid: '2026-07-05',
            userId: $cashierTwoId,
            verifiedByUserId: $cashierOneId,
        );

        $this->createPayment(
            targetType: 'booking',
            targetId: $bookingId,
            modeId: $cashId,
            amount: 300.00,
            status: 'Verified',
            datePaid: '2026-07-05',
            userId: $cashierTwoId,
            verifiedByUserId: $cashierTwoId,
        );

        $service = app(ReportsService::class);

        $cashierOneReport = $service->revenueReport(
            '2026-07-05',
            '2026-07-05',
            $cashierOneId,
            25,
            '',
            'reportPage',
            true,
        );

        $cashierTwoReport = $service->revenueReport(
            '2026-07-05',
            '2026-07-05',
            $cashierTwoId,
            25,
            '',
            'reportPage',
            true,
        );

        $this->assertSame(
            300.00,
            $cashierOneReport[
                'financial_metrics'
            ]['verified_revenue'],
        );

        $this->assertSame(
            2,
            $cashierOneReport[
                'financial_metrics'
            ]['verified_payment_count'],
        );

        $this->assertSame(
            500.00,
            $cashierTwoReport[
                'financial_metrics'
            ]['verified_revenue'],
        );

        $this->assertSame(
            2,
            $cashierTwoReport[
                'financial_metrics'
            ]['verified_payment_count'],
        );

        $this->assertFalse(
            $cashierOneReport[
                'show_outstanding_metrics'
            ],
        );

        $this->assertFalse(
            $cashierTwoReport[
                'show_outstanding_metrics'
            ],
        );
    }

    public function test_admin_revenue_report_exposes_global_outstanding_balance_snapshot(): void
    {
        $this->createBooking('Booked', 300.00);
        $this->createBooking('Cancelled', 900.00);

        $this->createReservation('Active', 150.00);
        $this->createReservation('No-show', 700.00);

        $this->createEntranceSlip('Unpaid', 50.00);
        $this->createEntranceSlip('Paid', 80.00);

        $report = app(ReportsService::class)
            ->revenueReport(
                '2026-07-01',
                '2026-07-31',
                null,
                25,
                '',
                'reportPage',
                true,
            );

        $metrics = $report['financial_metrics'];

        $this->assertSame(
            300.00,
            $metrics[
                'outstanding_booking_balance'
            ],
        );

        $this->assertSame(
            150.00,
            $metrics[
                'outstanding_reservation_balance'
            ],
        );

        $this->assertSame(
            50.00,
            $metrics[
                'outstanding_entrance_balance'
            ],
        );

        $this->assertSame(
            500.00,
            $metrics[
                'total_outstanding_balance'
            ],
        );
    }

    public function test_shared_report_output_contains_canonical_financial_summary_labels(): void
    {
        $content = file_get_contents(
            resource_path(
                'views/livewire/reports/partials/report-output.blade.php',
            ),
        );

        $this->assertIsString($content);

        foreach ([
            'Verified Revenue',
            'Verified Payments',
            'Booking Revenue',
            'Reservation Revenue',
            'Entrance Revenue',
            'Total Outstanding',
            'The summary cards cover all Verified payments',
        ] as $label) {
            $this->assertStringContainsString(
                $label,
                $content,
            );
        }
    }

    private function createPayment(
        string $targetType,
        int $targetId,
        int $modeId,
        float $amount,
        string $status,
        string $datePaid,
        ?int $userId,
        ?int $verifiedByUserId,
    ): int {
        $payload = [
            'p_ref_no' => 'P'.strtoupper(
                substr(
                    md5(uniqid('', true)),
                    0,
                    12,
                ),
            ),
            'booking_id' =>
                $targetType === 'booking'
                    ? $targetId
                    : null,
            'reservation_id' =>
                $targetType === 'reservation'
                    ? $targetId
                    : null,
            'entrance_slip_id' =>
                $targetType === 'entrance_slip'
                    ? $targetId
                    : null,
            'mode_of_payment_id' => $modeId,
            'reference_number' => null,
            'proof_of_payment_path' => null,
            'amount_paid' => $amount,
            'date_paid' => $datePaid,
            'user_id' => $userId,
            'payment_status' => $status,
            'verified_by_user_id' =>
                $verifiedByUserId,
            'verified_at' =>
                strtolower($status) === 'verified'
                    ? now()
                    : null,
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

        return DB::table('tbl_payment')
            ->insertGetId($payload);
    }

    private function createBooking(
        string $status,
        float $amountDue,
    ): int {
        $payload = [
            'b_ref_no' => 'B'.strtoupper(
                substr(
                    md5(uniqid('', true)),
                    0,
                    12,
                ),
            ),
            'guest_id' => $this->createGuest(),
            'booking_date' => '2026-07-01',
            'no_of_extra_guests' => 0,
            'total_price' => max(
                1000.00,
                $amountDue,
            ),
            'amount_due' => $amountDue,
            'user_id' => null,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (
            Schema::hasColumn(
                'tbl_booking',
                'total_guest_count',
            )
        ) {
            $payload['total_guest_count'] = 4;
        }

        return DB::table('tbl_booking')
            ->insertGetId($payload);
    }

    private function createReservation(
        string $status,
        float $amountDue,
    ): int {
        $payload = [
            'r_ref_no' => 'R'.strtoupper(
                substr(
                    md5(uniqid('', true)),
                    0,
                    12,
                ),
            ),
            'guest_id' => $this->createGuest(),
            'reservation_date' => '2026-07-01',
            'total_price' => max(
                1000.00,
                $amountDue,
            ),
            'amount_due' => $amountDue,
            'no_of_extra_guests' => 0,
            'user_id' => null,
            'status' => $status,
            'cancellation_reason' => null,
            'cancelled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (
            Schema::hasColumn(
                'tbl_reservation',
                'total_guest_count',
            )
        ) {
            $payload['total_guest_count'] = 4;
        }

        return DB::table('tbl_reservation')
            ->insertGetId($payload);
    }

    private function createEntranceSlip(
        string $status,
        float $amountDue,
    ): int {
        $securityId = $this->createUser(
            'Security Guard',
            'security_'.uniqid(),
        );

        $payload = [
            'no_of_adult' => 1,
            'no_of_children' => 0,
            'no_of_PWD_SC' => 0,
            'no_of_Male' => 1,
            'no_of_Female' => 0,
            'no_of_Tourist' => 0,
            'created_by_user_id' => $securityId,
            'guest_id' => null,
            'date_created' => '2026-07-01',
            'time_created' => '08:00:00',
            'total_price' => max(
                100.00,
                $amountDue,
            ),
            'amount_due' => $amountDue,
            'handled_by_user_id' =>
                $status === 'Paid'
                    ? $securityId
                    : null,
            'status' => $status,
        ];

        if (
            Schema::hasColumn(
                'tbl_entrance_slip',
                'created_at',
            )
        ) {
            $payload['created_at'] = now();
        }

        if (
            Schema::hasColumn(
                'tbl_entrance_slip',
                'updated_at',
            )
        ) {
            $payload['updated_at'] = now();
        }

        return DB::table('tbl_entrance_slip')
            ->insertGetId($payload);
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

    private function createGuest(): int
    {
        return DB::table('tbl_guest')
            ->insertGetId([
                'first_name' => 'Report',
                'middle_name' => null,
                'last_name' => 'Guest',
                'contact_no' => '09123456789',
                'address_id' =>
                    $this->createAddress(),
                'email' =>
                    uniqid().'@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createUser(
        string $roleName,
        string $username,
    ): int {
        $roleId = DB::table('tbl_role')
            ->where(
                'role_name',
                $roleName,
            )
            ->value('role_id');

        if ($roleId === null) {
            $roleId = DB::table('tbl_role')
                ->insertGetId([
                    'role_name' => $roleName,
                ]);
        }

        return DB::table('tbl_user')
            ->insertGetId([
                'first_name' => 'Test',
                'middle_name' => null,
                'last_name' =>
                    str_replace(
                        ' ',
                        '',
                        $roleName,
                    ),
                'username' => $username,
                'password' =>
                    Hash::make('password'),
                'email' =>
                    $username.'@example.test',
                'contact_no' => '09999999999',
                'status' => 'Active',
                'address_id' =>
                    $this->createAddress(),
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
}

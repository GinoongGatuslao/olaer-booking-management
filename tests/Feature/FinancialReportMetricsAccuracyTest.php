<?php

namespace Tests\Feature;

use App\Services\FinancialReportMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class FinancialReportMetricsAccuracyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_verified_payments_are_recognized_as_revenue(): void
    {
        $cashierId = $this->createUser('Cashier');
        $cashId = $this->createPaymentMode('Cash');
        $gcashId = $this->createPaymentMode('GCash');
        $cardId = $this->createPaymentMode('Card');

        $bookingId = $this->createBooking(
            'Booked',
            0.00,
        );

        $reservationId = $this->createReservation(
            'Paid',
            0.00,
        );

        $entranceSlipId = $this->createEntranceSlip(
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
        );

        $this->createPayment(
            targetType: 'booking',
            targetId: $bookingId,
            modeId: $gcashId,
            amount: 900.00,
            status: 'Pending',
            datePaid: '2026-07-01',
            userId: $cashierId,
        );

        $this->createPayment(
            targetType: 'reservation',
            targetId: $reservationId,
            modeId: $cashId,
            amount: 100.00,
            status: 'Rejected',
            datePaid: '2026-07-01',
            userId: $cashierId,
        );

        $this->createPayment(
            targetType: 'reservation',
            targetId: $reservationId,
            modeId: $gcashId,
            amount: 250.00,
            status: 'verified',
            datePaid: '2026-07-01',
            userId: $cashierId,
        );

        $this->createPayment(
            targetType: 'entrance_slip',
            targetId: $entranceSlipId,
            modeId: $cashId,
            amount: 100.00,
            status: 'Verified',
            datePaid: '2026-07-01',
            userId: $cashierId,
        );

        $this->createPayment(
            targetType: 'booking',
            targetId: $bookingId,
            modeId: $cardId,
            amount: 75.00,
            status: 'Verified',
            datePaid: '2026-07-01',
            userId: $cashierId,
        );

        $summary = app(
            FinancialReportMetricsService::class,
        )->summary();

        $this->assertSame(
            925.00,
            $summary['verified_revenue'],
        );

        $this->assertSame(
            4,
            $summary['verified_payment_count'],
        );

        $this->assertSame(
            575.00,
            $summary['booking_revenue'],
        );

        $this->assertSame(
            250.00,
            $summary['reservation_revenue'],
        );

        $this->assertSame(
            100.00,
            $summary['entrance_revenue'],
        );

        $this->assertSame(
            600.00,
            $summary['cash_revenue'],
        );

        $this->assertSame(
            250.00,
            $summary['gcash_revenue'],
        );

        $this->assertSame(
            75.00,
            $summary['other_mode_revenue'],
        );
    }

    public function test_date_filters_are_inclusive_and_daily_revenue_is_grouped(): void
    {
        $cashierId = $this->createUser('Cashier');
        $cashId = $this->createPaymentMode('Cash');
        $bookingId = $this->createBooking(
            'Checked-out',
            0.00,
        );

        foreach ([
            ['2026-07-01', 100.00],
            ['2026-07-02', 200.00],
            ['2026-07-02', 50.00],
            ['2026-07-03', 300.00],
        ] as [$date, $amount]) {
            $this->createPayment(
                targetType: 'booking',
                targetId: $bookingId,
                modeId: $cashId,
                amount: $amount,
                status: 'Verified',
                datePaid: $date,
                userId: $cashierId,
            );
        }

        $service = app(
            FinancialReportMetricsService::class,
        );

        $summary = $service->summary(
            '2026-07-02',
            '2026-07-02',
        );

        $this->assertSame(
            '2026-07-02',
            $summary['date_from'],
        );

        $this->assertSame(
            '2026-07-02',
            $summary['date_to'],
        );

        $this->assertSame(
            250.00,
            $summary['verified_revenue'],
        );

        $this->assertSame(
            2,
            $summary['verified_payment_count'],
        );

        $daily = $service->dailyVerifiedRevenue(
            '2026-07-01',
            '2026-07-02',
        );

        $this->assertSame([
            [
                'date' => '2026-07-01',
                'payment_count' => 1,
                'revenue' => 100.00,
            ],
            [
                'date' => '2026-07-02',
                'payment_count' => 2,
                'revenue' => 250.00,
            ],
        ], $daily);
    }

    public function test_outstanding_balances_only_include_operationally_payable_records(): void
    {
        $this->createBooking('Booked', 300.00);
        $this->createBooking('Checked-in', 200.00);
        $this->createBooking('Cancelled', 400.00);
        $this->createBooking(
            'Payment Rejected',
            500.00,
        );
        $this->createBooking(
            'Checked-out',
            600.00,
        );

        $this->createReservation('Active', 150.00);
        $this->createReservation(
            'Cancelled',
            250.00,
        );
        $this->createReservation('No-show', 350.00);

        $this->createEntranceSlip('Unpaid', 50.00);
        $this->createEntranceSlip('Paid', 75.00);

        $summary = app(
            FinancialReportMetricsService::class,
        )->summary();

        $this->assertSame(
            500.00,
            $summary[
                'outstanding_booking_balance'
            ],
        );

        $this->assertSame(
            150.00,
            $summary[
                'outstanding_reservation_balance'
            ],
        );

        $this->assertSame(
            50.00,
            $summary[
                'outstanding_entrance_balance'
            ],
        );

        $this->assertSame(
            700.00,
            $summary[
                'total_outstanding_balance'
            ],
        );
    }

    public function test_checked_out_booking_payment_remains_historical_revenue(): void
    {
        $cashierId = $this->createUser('Cashier');
        $cashId = $this->createPaymentMode('Cash');

        $bookingId = $this->createBooking(
            'Checked-out',
            0.00,
        );

        $this->createPayment(
            targetType: 'booking',
            targetId: $bookingId,
            modeId: $cashId,
            amount: 1000.00,
            status: 'Verified',
            datePaid: '2026-07-10',
            userId: $cashierId,
        );

        $summary = app(
            FinancialReportMetricsService::class,
        )->summary(
            '2026-07-10',
            '2026-07-10',
        );

        $this->assertSame(
            1000.00,
            $summary['verified_revenue'],
        );

        $this->assertSame(
            1000.00,
            $summary['booking_revenue'],
        );

        $this->assertSame(
            0.00,
            $summary[
                'outstanding_booking_balance'
            ],
        );
    }

    public function test_pending_and_rejected_payments_do_not_appear_in_daily_revenue(): void
    {
        $cashierId = $this->createUser('Cashier');
        $gcashId = $this->createPaymentMode('GCash');
        $bookingId = $this->createBooking(
            'Pending Verification',
            1000.00,
        );

        foreach (['Pending', 'Rejected'] as $status) {
            $this->createPayment(
                targetType: 'booking',
                targetId: $bookingId,
                modeId: $gcashId,
                amount: 1000.00,
                status: $status,
                datePaid: '2026-07-15',
                userId: $cashierId,
            );
        }

        $service = app(
            FinancialReportMetricsService::class,
        );

        $this->assertSame(
            0.00,
            $service->summary(
                '2026-07-15',
                '2026-07-15',
            )['verified_revenue'],
        );

        $this->assertSame(
            [],
            $service->dailyVerifiedRevenue(
                '2026-07-15',
                '2026-07-15',
            ),
        );
    }

    public function test_invalid_report_date_range_is_rejected(): void
    {
        $this->expectException(
            InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'Report start date cannot be after the end date.',
        );

        app(FinancialReportMetricsService::class)
            ->summary(
                '2026-07-31',
                '2026-07-01',
            );
    }

    private function createPayment(
        string $targetType,
        int $targetId,
        int $modeId,
        float $amount,
        string $status,
        string $datePaid,
        int $userId,
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
                strtolower($status) === 'verified'
                    ? $userId
                    : null,
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
            $payload['rejection_reason'] =
                strtolower($status) === 'rejected'
                    ? 'Rejected test payment.'
                    : null;
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
            'booking_date' => now()->toDateString(),
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
            'reservation_date' =>
                now()->toDateString(),
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
        $userId = $this->createUser(
            'Security Guard',
        );

        $payload = [
            'no_of_adult' => 1,
            'no_of_children' => 0,
            'no_of_PWD_SC' => 0,
            'no_of_Male' => 1,
            'no_of_Female' => 0,
            'no_of_Tourist' => 0,
            'created_by_user_id' => $userId,
            'guest_id' => null,
            'date_created' =>
                now()->toDateString(),
            'time_created' =>
                now()->format('H:i:s'),
            'total_price' => max(
                100.00,
                $amountDue,
            ),
            'amount_due' => $amountDue,
            'handled_by_user_id' =>
                $status === 'Paid'
                    ? $userId
                    : null,
            'status' => $status,
        ];

        $this->addTimestampsIfPresent(
            'tbl_entrance_slip',
            $payload,
        );

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
            ->value(
                'mode_of_payment_id',
            );

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
                'first_name' => 'Financial',
                'middle_name' => null,
                'last_name' => 'Report Guest',
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

        $username = strtolower(
            str_replace(' ', '_', $roleName),
        ).uniqid();

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

    private function addTimestampsIfPresent(
        string $table,
        array &$payload,
    ): void {
        if (
            Schema::hasColumn(
                $table,
                'created_at',
            )
        ) {
            $payload['created_at'] = now();
        }

        if (
            Schema::hasColumn(
                $table,
                'updated_at',
            )
        ) {
            $payload['updated_at'] = now();
        }
    }
}

<?php

namespace Tests\Feature;

use App\Services\PaymentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class PaymentTargetStateHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_rejected_booking_cannot_accept_cashier_payment(): void
    {
        $cashierId = $this->createUser('Cashier');
        $modeId = $this->createPaymentMode('Cash');
        $bookingId = $this->createBooking(
            status: 'Payment Rejected',
            amountDue: 500.00,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'This booking can no longer accept payments.',
        );

        app(PaymentWorkflowService::class)
            ->recordCashierPayment([
                'target_type' => 'booking',
                'target_id' => $bookingId,
                'amount_paid' => 500.00,
                'mode_of_payment_id' => $modeId,
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);
    }

    public function test_pending_verification_booking_cannot_accept_manual_cashier_payment(): void
    {
        $cashierId = $this->createUser('Cashier');
        $modeId = $this->createPaymentMode('Cash');
        $bookingId = $this->createBooking(
            status: 'Pending Verification',
            amountDue: 1000.00,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'This booking can no longer accept payments.',
        );

        app(PaymentWorkflowService::class)
            ->recordCashierPayment([
                'target_type' => 'booking',
                'target_id' => $bookingId,
                'amount_paid' => 1000.00,
                'mode_of_payment_id' => $modeId,
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);
    }

    public function test_checked_in_booking_payment_does_not_control_amenity_request_state(): void
    {
        $cashierId = $this->createUser('Cashier');
        $modeId = $this->createPaymentMode('Cash');
        $bookingId = $this->createBooking(
            status: 'Checked-in',
            amountDue: 300.00,
        );

        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Pending',
            totalPrice: 300.00,
        );

        $payment = app(PaymentWorkflowService::class)
            ->recordCashierPayment([
                'target_type' => 'booking',
                'target_id' => $bookingId,
                'amount_paid' => 300.00,
                'mode_of_payment_id' => $modeId,
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);

        $this->assertSame('Verified', $payment->payment_status);

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $bookingId,
            'amount_due' => 0.00,
            'status' => 'Checked-in',
        ]);

        $this->assertDatabaseHas('tbl_amenity_request', [
            'amenity_request_id' => $requestId,
            'amenity_request_status' => 'Pending',
        ]);
    }

    public function test_checked_out_booking_cannot_accept_payment_even_with_balance(): void
    {
        $cashierId = $this->createUser('Cashier');
        $modeId = $this->createPaymentMode('Cash');
        $bookingId = $this->createBooking(
            status: 'Checked-out',
            amountDue: 100.00,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'This booking can no longer accept payments.',
        );

        app(PaymentWorkflowService::class)
            ->recordCashierPayment([
                'target_type' => 'booking',
                'target_id' => $bookingId,
                'amount_paid' => 100.00,
                'mode_of_payment_id' => $modeId,
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);
    }

    public function test_non_active_reservation_cannot_accept_payment(): void
    {
        $cashierId = $this->createUser('Cashier');
        $modeId = $this->createPaymentMode('Cash');
        $reservationId = $this->createReservation(
            status: 'Paid',
            amountDue: 100.00,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'This reservation can no longer accept payments.',
        );

        app(PaymentWorkflowService::class)
            ->recordCashierPayment([
                'target_type' => 'reservation',
                'target_id' => $reservationId,
                'amount_paid' => 100.00,
                'mode_of_payment_id' => $modeId,
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);
    }

    public function test_active_reservation_payment_can_settle_balance(): void
    {
        $cashierId = $this->createUser('Cashier');
        $modeId = $this->createPaymentMode('Cash');
        $reservationId = $this->createReservation(
            status: 'Active',
            amountDue: 100.00,
        );

        app(PaymentWorkflowService::class)
            ->recordCashierPayment([
                'target_type' => 'reservation',
                'target_id' => $reservationId,
                'amount_paid' => 100.00,
                'mode_of_payment_id' => $modeId,
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);

        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' => $reservationId,
            'amount_due' => 0.00,
            'status' => 'Paid',
        ]);
    }

    private function createBooking(
        string $status,
        float $amountDue,
    ): int {
        $guestId = $this->createGuest();

        $payload = [
            'b_ref_no' => 'B'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_price' => max(1000.00, $amountDue),
            'amount_due' => $amountDue,
            'user_id' => null,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('tbl_booking', 'total_guest_count')) {
            $payload['total_guest_count'] = 4;
        }

        return DB::table('tbl_booking')
            ->insertGetId($payload);
    }

    private function createReservation(
        string $status,
        float $amountDue,
    ): int {
        $guestId = $this->createGuest();

        $payload = [
            'r_ref_no' => 'R'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'reservation_date' => now()->toDateString(),
            'total_price' => max(1000.00, $amountDue),
            'amount_due' => $amountDue,
            'no_of_extra_guests' => 0,
            'user_id' => null,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('tbl_reservation', 'total_guest_count')) {
            $payload['total_guest_count'] = 4;
        }

        return DB::table('tbl_reservation')
            ->insertGetId($payload);
    }

    private function createAmenityRequest(
        int $bookingId,
        string $status,
        float $totalPrice,
    ): int {
        return DB::table('tbl_amenity_request')
            ->insertGetId([
                'booking_id' => $bookingId,
                'amenity_request_status' => $status,
                'total_price' => $totalPrice,
                'date_created' => now()->toDateString(),
                'user_id' => null,
                'assigned_to_user_id' => null,
                'delivered_at' => null,
                'cancelled_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createGuest(): int
    {
        $addressId = DB::table('tbl_address')
            ->insertGetId([
                'purok' => 'Purok 1',
                'province' => 'Sultan Kudarat',
                'city' => 'Tacurong City',
                'barangay' => 'Poblacion',
            ]);

        return DB::table('tbl_guest')
            ->insertGetId([
                'first_name' => 'Payment',
                'middle_name' => null,
                'last_name' => 'Guest',
                'contact_no' => '09123456789',
                'address_id' => $addressId,
                'email' => uniqid().'@example.test',
                'created_at' => now(),
                'updated_at' => now(),
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

        $addressId = DB::table('tbl_address')
            ->insertGetId([
                'purok' => 'Purok 1',
                'province' => 'Sultan Kudarat',
                'city' => 'Tacurong City',
                'barangay' => 'Poblacion',
            ]);

        return DB::table('tbl_user')
            ->insertGetId([
                'first_name' => 'Test',
                'middle_name' => null,
                'last_name' => str_replace(' ', '', $roleName),
                'username' => strtolower(
                    str_replace(' ', '_', $roleName),
                ).uniqid(),
                'password' => 'password',
                'email' => uniqid().'@example.test',
                'contact_no' => '09000000000',
                'status' => 'Active',
                'address_id' => $addressId,
                'role_id' => $roleId,
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createPaymentMode(string $mode): int
    {
        $existing = DB::table('tbl_mode_of_payment')
            ->where('mode_of_payment', $mode)
            ->value('mode_of_payment_id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return DB::table('tbl_mode_of_payment')
            ->insertGetId([
                'mode_of_payment' => $mode,
            ]);
    }
}

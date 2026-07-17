<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\GcashPaymentVerificationService;
use App\Services\GcashReferenceIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class GcashPaymentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_cashier_can_verify_gcash_payment(): void
    {
        $payment = $this->createPendingGcashPayment();
        $manager = $this->createUser('Manager');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Only a Cashier may verify or reject GCash payments.',
        );

        app(GcashPaymentVerificationService::class)
            ->verify(
                (int) $payment->payment_id,
                (int) $manager->user_id,
            );
    }

    public function test_verification_requires_exact_booking_balance(): void
    {
        $payment = $this->createPendingGcashPayment(
            amountDue: 1000.00,
            amountPaid: 900.00,
        );

        $cashier = $this->createUser('Cashier');

        try {
            app(GcashPaymentVerificationService::class)
                ->verify(
                    (int) $payment->payment_id,
                    (int) $cashier->user_id,
                );

            $this->fail(
                'Mismatched GCash amount should not be verified.',
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'exactly match',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('tbl_payment', [
            'payment_id' => $payment->payment_id,
            'payment_status' => 'Pending',
        ]);

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $payment->booking_id,
            'amount_due' => 1000.00,
            'status' => 'Pending Verification',
        ]);
    }

    public function test_verification_is_idempotent_and_does_not_double_deduct(): void
    {
        $payment = $this->createPendingGcashPayment();
        $cashier = $this->createUser('Cashier');
        $service = app(GcashPaymentVerificationService::class);

        $first = $service->verify(
            (int) $payment->payment_id,
            (int) $cashier->user_id,
        );

        $second = $service->verify(
            (int) $payment->payment_id,
            (int) $cashier->user_id,
        );

        $this->assertSame(
            'Verified',
            $first->payment_status,
        );

        $this->assertSame(
            'Verified',
            $second->payment_status,
        );

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $payment->booking_id,
            'amount_due' => 0.00,
            'status' => 'Booked',
        ]);

        $this->assertDatabaseHas('tbl_booking_details', [
            'booking_id' => $payment->booking_id,
            'status' => 'Booked',
            'user_id' => $cashier->user_id,
        ]);
    }

    public function test_rejection_reason_is_persisted_and_rejection_is_idempotent(): void
    {
        $payment = $this->createPendingGcashPayment();
        $cashier = $this->createUser('Cashier');
        $service = app(GcashPaymentVerificationService::class);

        $first = $service->reject(
            (int) $payment->payment_id,
            (int) $cashier->user_id,
            'Uploaded proof is unreadable.',
        );

        $second = $service->reject(
            (int) $payment->payment_id,
            (int) $cashier->user_id,
            'A later duplicate request must not overwrite the audit reason.',
        );

        $this->assertSame(
            'Rejected',
            $first->payment_status,
        );

        $this->assertSame(
            'Uploaded proof is unreadable.',
            $second->rejection_reason,
        );

        $this->assertDatabaseHas('tbl_payment', [
            'payment_id' => $payment->payment_id,
            'payment_status' => 'Rejected',
            'rejection_reason' => 'Uploaded proof is unreadable.',
            'verified_by_user_id' => $cashier->user_id,
        ]);

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $payment->booking_id,
            'status' => 'Payment Rejected',
        ]);
    }

    public function test_verified_payment_cannot_be_rejected(): void
    {
        $payment = $this->createPendingGcashPayment();
        $cashier = $this->createUser('Cashier');
        $service = app(GcashPaymentVerificationService::class);

        $service->verify(
            (int) $payment->payment_id,
            (int) $cashier->user_id,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A verified GCash payment cannot be rejected.',
        );

        $service->reject(
            (int) $payment->payment_id,
            (int) $cashier->user_id,
            'Incorrect rejection attempt.',
        );
    }

    public function test_duplicate_gcash_reference_is_rejected_with_friendly_message(): void
    {
        $payment = $this->createPendingGcashPayment(
            referenceNumber: '  gcash-123  ',
        );

        $this->assertSame(
            'GCASH-123',
            app(GcashReferenceIntegrityService::class)
                ->normalize('  gcash-123  '),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'This GCash reference number has already been used.',
        );

        app(GcashReferenceIntegrityService::class)
            ->assertAvailable(
                (string) $payment->reference_number,
            );
    }

    private function createPendingGcashPayment(
        float $amountDue = 1000.00,
        float $amountPaid = 1000.00,
        string $referenceNumber = 'GCASH123456789',
    ): Payment {
        $guestId = $this->createGuest();
        $facilityId = $this->createFacility();

        $booking = [
            'b_ref_no' => 'B'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_price' => $amountDue,
            'amount_due' => $amountDue,
            'user_id' => null,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'status' => 'Pending Verification',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (
            Schema::hasColumn(
                'tbl_booking',
                'total_guest_count',
            )
        ) {
            $booking['total_guest_count'] = 4;
        }

        $bookingId = DB::table('tbl_booking')
            ->insertGetId($booking);

        DB::table('tbl_booking_details')->insert([
            'booking_id' => $bookingId,
            'facility_id' => $facilityId,
            'rate_type' => 'Overnight',
            'check_in_date' => '2026-12-10',
            'check_out_date' => '2026-12-11',
            'check_in_time' => '12:00:00',
            'status' => 'Pending Verification',
            'discount_id' => null,
            'user_id' => null,
        ]);

        $mode = ModeOfPayment::query()->firstOrCreate([
            'mode_of_payment' => 'GCash',
        ]);

        return Payment::query()->create([
            'p_ref_no' => 'P'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'booking_id' => $bookingId,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'mode_of_payment_id' =>
                $mode->mode_of_payment_id,
            'reference_number' =>
                strtoupper(
                    preg_replace(
                        '/\s+/',
                        '',
                        trim($referenceNumber),
                    ) ?? '',
                ),
            'proof_of_payment_path' =>
                'gcash-proofs/test-proof.png',
            'amount_paid' => $amountPaid,
            'date_paid' => now()->toDateString(),
            'user_id' => null,
            'payment_status' => 'Pending',
            'rejection_reason' => null,
            'verified_by_user_id' => null,
            'verified_at' => null,
        ]);
    }

    private function createGuest(): int
    {
        $addressId = DB::table('tbl_address')
            ->insertGetId([
                'purok' => 'Purok 1',
                'province' => 'Sultan Kudarat',
                'city' => 'Tacurong City',
                'barangay' => 'Test Barangay',
            ]);

        return DB::table('tbl_guest')
            ->insertGetId([
                'first_name' => 'GCash',
                'middle_name' => null,
                'last_name' => 'Guest',
                'contact_no' => '09123456789',
                'address_id' => $addressId,
                'email' => uniqid().'@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createFacility(): int
    {
        $typeId = DB::table('tbl_facility_type')
            ->where('facility_type', 'Room')
            ->value('facility_type_id');

        if ($typeId === null) {
            $typeId = DB::table('tbl_facility_type')
                ->insertGetId([
                    'facility_type' => 'Room',
                ]);
        }

        return DB::table('tbl_facility')
            ->insertGetId([
                'facility_name' => 'Room '.uniqid(),
                'facility_type_id' => $typeId,
                'facility_size' => 'Standard',
                'facility_status' => 'Available',
                'capacity' => '10',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createUser(string $roleName): User
    {
        $role = Role::query()->firstOrCreate([
            'role_name' => $roleName,
        ]);

        $address = Address::query()->create([
            'purok' => 'Purok 1',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'barangay' => 'Test Barangay',
        ]);

        return User::query()->create([
            'first_name' => 'Test',
            'middle_name' => null,
            'last_name' => str_replace(' ', '', $roleName),
            'username' => strtolower(
                str_replace(' ', '_', $roleName),
            ).uniqid(),
            'password' => 'password',
            'email' => uniqid().'@example.test',
            'contact_no' => '09123456789',
            'status' => 'Active',
            'address_id' => $address->address_id,
            'role_id' => $role->role_id,
        ]);
    }
}

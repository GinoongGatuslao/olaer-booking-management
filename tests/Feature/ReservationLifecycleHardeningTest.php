<?php

namespace Tests\Feature;

use App\Models\GuestVerificationOtp;
use App\Services\BookingAvailabilityService;
use App\Services\ReservationNoShowReleaseService;
use App\Services\StaffReservationCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class ReservationLifecycleHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_cashier_can_cancel_staff_reservation(): void
    {
        $managerId = $this->createUser('Manager');
        $reservation = $this->createReservationScenario();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Only a Cashier may cancel reservations.',
        );

        app(StaffReservationCancellationService::class)
            ->cancel(
                $reservation['reservation_id'],
                'Guest changed plans.',
                $managerId,
            );
    }

    public function test_staff_cancellation_preserves_balance_and_releases_schedule(): void
    {
        $cashierId = $this->createUser('Cashier');
        $reservation = $this->createReservationScenario(
            amountDue: 750.00,
            totalPrice: 1000.00,
        );

        $availability = app(
            BookingAvailabilityService::class,
        );

        $this->assertFalse(
            $availability->isFacilityAvailable(
                $reservation['facility_id'],
                $reservation['check_in_date'],
                $reservation['check_out_date'],
            ),
        );

        $cancelled = app(
            StaffReservationCancellationService::class,
        )->cancel(
            $reservation['reservation_id'],
            'Guest requested cancellation before payment.',
            $cashierId,
        );

        $this->assertSame(
            'Cancelled',
            $cancelled->status,
        );

        $this->assertSame(
            '750.00',
            (string) $cancelled->amount_due,
        );

        $this->assertSame(
            'Guest requested cancellation before payment.',
            $cancelled->cancellation_reason,
        );

        $this->assertNotNull($cancelled->cancelled_at);

        $this->assertTrue(
            $availability->isFacilityAvailable(
                $reservation['facility_id'],
                $reservation['check_in_date'],
                $reservation['check_out_date'],
            ),
        );
    }

    public function test_verified_payment_blocks_staff_cancellation(): void
    {
        $cashierId = $this->createUser('Cashier');
        $reservation = $this->createReservationScenario();

        $this->createVerifiedReservationPayment(
            $reservation['reservation_id'],
            $cashierId,
            250.00,
        );

        try {
            app(
                StaffReservationCancellationService::class,
            )->cancel(
                $reservation['reservation_id'],
                'Attempted cancellation.',
                $cashierId,
            );

            $this->fail(
                'Expected verified payment to block cancellation.',
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'This reservation has a verified payment and cannot be cancelled because the resort does not process refunds.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' =>
                $reservation['reservation_id'],
            'status' => 'Active',
            'amount_due' => 1000.00,
        ]);
    }

    public function test_linked_booking_blocks_staff_cancellation(): void
    {
        $cashierId = $this->createUser('Cashier');
        $reservation = $this->createReservationScenario();

        $this->createLinkedBooking(
            $reservation['reservation_id'],
            $reservation['guest_id'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'This reservation already has a linked booking and cannot be cancelled.',
        );

        app(StaffReservationCancellationService::class)
            ->cancel(
                $reservation['reservation_id'],
                'Attempted cancellation.',
                $cashierId,
            );
    }

    public function test_staff_cancellation_expires_active_management_otp(): void
    {
        Carbon::setTestNow(
            '2026-08-01 10:00:00',
        );

        $cashierId = $this->createUser('Cashier');
        $reservation = $this->createReservationScenario();

        $otpId = $this->createManagementOtp(
            $reservation['reservation_id'],
            '2030-01-01 00:00:00',
        );

        app(StaffReservationCancellationService::class)
            ->cancel(
                $reservation['reservation_id'],
                'Guest cancelled before arrival.',
                $cashierId,
            );

        $otp = GuestVerificationOtp::query()
            ->findOrFail($otpId);

        $this->assertTrue($otp->expires_at->isPast());
        $this->assertNull($otp->verified_at);
    }

    public function test_no_show_release_expires_otp_and_preserves_balance(): void
    {
        Carbon::setTestNow(
            '2026-08-02 03:00:00',
        );

        $reservation = $this->createReservationScenario(
            amountDue: 900.00,
            totalPrice: 1000.00,
            checkInDate: '2026-08-01',
            checkOutDate: '2026-08-02',
        );

        $otpId = $this->createManagementOtp(
            $reservation['reservation_id'],
            '2030-01-01 00:00:00',
        );

        $released = app(
            ReservationNoShowReleaseService::class,
        )->expirePastUnpaidReservations(
            '2026-08-02',
        );

        $this->assertSame(1, $released);

        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' =>
                $reservation['reservation_id'],
            'status' => 'No-show',
            'amount_due' => 900.00,
        ]);

        $otp = GuestVerificationOtp::query()
            ->findOrFail($otpId);

        $this->assertTrue($otp->expires_at->isPast());
    }

    public function test_no_show_command_is_registered_in_scheduler(): void
    {
        $exitCode = Artisan::call('schedule:list');

        $this->assertSame(0, $exitCode);

        $this->assertStringContainsString(
            'olaer:release-no-show-reservations',
            Artisan::output(),
        );

        $this->assertMatchesRegularExpression(
            '/^(?:[01]\d|2[0-3]):[0-5]\d$/',
            (string) config(
                'olaer.no_show_release_time',
            ),
        );

        $this->assertGreaterThanOrEqual(
            1,
            (int) config(
                'olaer.no_show_release_limit',
            ),
        );
    }

    /**
     * @return array{
     *   reservation_id:int,
     *   guest_id:int,
     *   facility_id:int,
     *   check_in_date:string,
     *   check_out_date:string
     * }
     */
    private function createReservationScenario(
        float $amountDue = 1000.00,
        float $totalPrice = 1000.00,
        ?string $checkInDate = null,
        ?string $checkOutDate = null,
    ): array {
        $guestId = $this->createGuest();
        $facilityId = $this->createFacility();

        $checkInDate ??= now()
            ->addDays(5)
            ->toDateString();

        $checkOutDate ??= now()
            ->addDays(6)
            ->toDateString();

        $payload = [
            'r_ref_no' => 'R'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'reservation_date' =>
                now()->toDateString(),
            'total_price' => $totalPrice,
            'amount_due' => $amountDue,
            'no_of_extra_guests' => 0,
            'user_id' => null,
            'status' => 'Active',
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

        $reservationId = DB::table(
            'tbl_reservation',
        )->insertGetId($payload);

        DB::table(
            'tbl_reservation_details',
        )->insert([
            'reservation_id' => $reservationId,
            'facility_id' => $facilityId,
            'rate_type' => 'Overnight',
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'discount_id' => null,
        ]);

        return [
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'facility_id' => $facilityId,
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
        ];
    }

    private function createManagementOtp(
        int $reservationId,
        string $expiresAt,
    ): int {
        return DB::table(
            'tbl_guest_verification_otp',
        )->insertGetId([
            'reservation_id' => $reservationId,
            'email' => uniqid().'@example.test',
            'purpose' => 'reservation_manage',
            'otp_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => $expiresAt,
            'verified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createVerifiedReservationPayment(
        int $reservationId,
        int $cashierId,
        float $amount,
    ): int {
        $modeId = $this->createPaymentMode('Cash');

        $payload = [
            'p_ref_no' => 'P'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'booking_id' => null,
            'reservation_id' => $reservationId,
            'entrance_slip_id' => null,
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

        return DB::table('tbl_payment')
            ->insertGetId($payload);
    }

    private function createLinkedBooking(
        int $reservationId,
        int $guestId,
    ): int {
        $payload = [
            'b_ref_no' => 'B'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_price' => 1000.00,
            'amount_due' => 0.00,
            'user_id' => null,
            'reservation_id' => $reservationId,
            'entrance_slip_id' => null,
            'status' => 'Booked',
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

    private function createPaymentMode(
        string $name,
    ): int {
        $existing = DB::table(
            'tbl_mode_of_payment',
        )
            ->where('mode_of_payment', $name)
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

    private function createFacility(): int
    {
        $typeId = DB::table('tbl_facility_type')
            ->where('facility_type', 'Room')
            ->value('facility_type_id');

        if ($typeId === null) {
            $typeId = DB::table(
                'tbl_facility_type',
            )->insertGetId([
                'facility_type' => 'Room',
            ]);
        }

        return DB::table('tbl_facility')
            ->insertGetId([
                'facility_name' =>
                    'Room '.uniqid(),
                'facility_type_id' => $typeId,
                'facility_size' => 'Standard',
                'facility_status' => 'Available',
                'capacity' => '10',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createGuest(): int
    {
        return DB::table('tbl_guest')
            ->insertGetId([
                'first_name' => 'Reservation',
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
    ): int {
        $roleId = DB::table('tbl_role')
            ->where('role_name', $roleName)
            ->value('role_id');

        if ($roleId === null) {
            $roleId = DB::table(
                'tbl_role',
            )->insertGetId([
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
                    str_replace(' ', '', $roleName),
                'username' => $username,
                'password' => Hash::make('password'),
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

<?php

namespace Tests\Feature;

use App\Services\CheckInWorkflowService;
use App\Services\ReservationToBookingWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class CheckInAndReservationConversionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_cashier_can_check_in_guest(): void
    {
        $managerId = $this->createUser('Manager');
        [, $detailId] = $this->createBookingScenario(
            'Booked',
            'Booked',
            0.00,
            'Available',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Only a Cashier may check in guests.',
        );

        app(CheckInWorkflowService::class)
            ->checkInBookingDetail($detailId, $managerId);
    }

    public function test_pending_verification_booking_cannot_be_checked_in(): void
    {
        $cashierId = $this->createUser('Cashier');
        [, $detailId] = $this->createBookingScenario(
            'Pending Verification',
            'Booked',
            0.00,
            'Available',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'This booking can no longer be checked in.',
        );

        app(CheckInWorkflowService::class)
            ->checkInBookingDetail($detailId, $cashierId);
    }

    public function test_occupied_facility_cannot_be_checked_in_again(): void
    {
        $cashierId = $this->createUser('Cashier');
        [, $detailId] = $this->createBookingScenario(
            'Booked',
            'Booked',
            0.00,
            'Occupied',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The assigned facility must be Available before check-in.',
        );

        app(CheckInWorkflowService::class)
            ->checkInBookingDetail($detailId, $cashierId);
    }

    public function test_guest_cannot_check_in_before_scheduled_date(): void
    {
        $cashierId = $this->createUser('Cashier');
        [, $detailId] = $this->createBookingScenario(
            'Booked',
            'Booked',
            0.00,
            'Available',
            now()->addDay()->toDateString(),
            now()->addDays(2)->toDateString(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The guest cannot be checked in before the scheduled check-in date.',
        );

        app(CheckInWorkflowService::class)
            ->checkInBookingDetail($detailId, $cashierId);
    }

    public function test_check_in_preserves_partially_checked_out_parent_status(): void
    {
        $cashierId = $this->createUser('Cashier');
        $bookingId = $this->createBooking(
            $this->createGuest(),
            'Partially Checked-out',
            0.00,
        );

        $this->createBookingDetail(
            $bookingId,
            $this->createFacility('Available'),
            'Checked-out',
        );

        $newDetailId = $this->createBookingDetail(
            $bookingId,
            $this->createFacility('Available'),
            'Booked',
        );

        app(CheckInWorkflowService::class)
            ->checkInBookingDetail($newDetailId, $cashierId);

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $bookingId,
            'status' => 'Partially Checked-out',
        ]);

        $this->assertDatabaseHas('tbl_booking_details', [
            'booking_details_id' => $newDetailId,
            'status' => 'Checked-in',
            'user_id' => $cashierId,
        ]);
    }

    public function test_only_cashier_can_convert_reservation(): void
    {
        $managerId = $this->createUser('Manager');
        $reservation = $this->createReservationScenario(
            'Paid',
            0.00,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Only a Cashier may convert reservations to bookings.',
        );

        app(ReservationToBookingWorkflowService::class)
            ->convert($reservation['reservation_id'], [
                'payment_amount' => 0,
                'mode_of_payment_id' => null,
                'reference_number' => '',
                'user_id' => $managerId,
            ]);
    }

    public function test_conversion_is_blocked_by_overlapping_booking(): void
    {
        $cashierId = $this->createUser('Cashier');
        $reservation = $this->createReservationScenario(
            'Paid',
            0.00,
        );

        $conflictBookingId = $this->createBooking(
            $this->createGuest(),
            'Booked',
            0.00,
        );

        $this->createBookingDetail(
            $conflictBookingId,
            $reservation['facility_id'],
            'Booked',
            $reservation['check_in_date'],
            $reservation['check_out_date'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Selected facility is not available for the selected date range.',
        );

        app(ReservationToBookingWorkflowService::class)
            ->convert($reservation['reservation_id'], [
                'payment_amount' => 0,
                'mode_of_payment_id' => null,
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);
    }

    public function test_conversion_preserves_data_and_records_exact_payment(): void
    {
        $cashierId = $this->createUser('Cashier');
        $cashModeId = $this->createModeOfPayment('Cash');

        $reservation = $this->createReservationScenario(
            'Active',
            1000.00,
            totalGuestCount: 5,
            extraGuestCount: 1,
            withDiscount: true,
            withExtraGuest: true,
        );

        $booking = app(
            ReservationToBookingWorkflowService::class,
        )->convert($reservation['reservation_id'], [
            'payment_amount' => 1000.00,
            'mode_of_payment_id' => $cashModeId,
            'reference_number' => '',
            'user_id' => $cashierId,
        ]);

        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' => $reservation['reservation_id'],
            'status' => 'Converted',
            'amount_due' => 0.00,
        ]);

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $booking->booking_id,
            'reservation_id' => $reservation['reservation_id'],
            'guest_id' => $reservation['guest_id'],
            'status' => 'Booked',
            'total_guest_count' => 5,
            'no_of_extra_guests' => 1,
            'total_price' => 1000.00,
            'amount_due' => 0.00,
            'user_id' => $cashierId,
        ]);

        $this->assertDatabaseHas('tbl_booking_details', [
            'booking_id' => $booking->booking_id,
            'facility_id' => $reservation['facility_id'],
            'rate_type' => 'Overnight',
            'check_in_date' => $reservation['check_in_date'],
            'check_out_date' => $reservation['check_out_date'],
            'status' => 'Booked',
            'discount_id' => $reservation['discount_id'],
            'base_price' => 1000.00,
            'discount_amount' => 100.00,
            'extra_guest_fee' => 100.00,
            'line_total' => 1000.00,
        ]);

        $this->assertDatabaseHas('tbl_booking_extra_guests', [
            'booking_id' => $booking->booking_id,
            'first_name' => 'Extra',
            'last_name' => 'Guest',
        ]);

        $this->assertDatabaseHas('tbl_payment', [
            'booking_id' => $booking->booking_id,
            'reservation_id' => $reservation['reservation_id'],
            'amount_paid' => 1000.00,
            'payment_status' => 'Verified',
            'verified_by_user_id' => $cashierId,
        ]);
    }

    public function test_same_reservation_cannot_be_converted_twice(): void
    {
        $cashierId = $this->createUser('Cashier');
        $reservation = $this->createReservationScenario(
            'Paid',
            0.00,
        );

        $service = app(
            ReservationToBookingWorkflowService::class,
        );

        $payload = [
            'payment_amount' => 0,
            'mode_of_payment_id' => null,
            'reference_number' => '',
            'user_id' => $cashierId,
        ];

        $service->convert(
            $reservation['reservation_id'],
            $payload,
        );

        $this->expectException(InvalidArgumentException::class);

        $service->convert(
            $reservation['reservation_id'],
            $payload,
        );
    }

    public function test_paid_reservation_with_balance_is_blocked(): void
    {
        $cashierId = $this->createUser('Cashier');
        $reservation = $this->createReservationScenario(
            'Paid',
            100.00,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'This paid reservation has an inconsistent remaining balance and must be reviewed before conversion.',
        );

        app(ReservationToBookingWorkflowService::class)
            ->convert($reservation['reservation_id'], [
                'payment_amount' => 100.00,
                'mode_of_payment_id' =>
                    $this->createModeOfPayment('Cash'),
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);
    }

    private function createBookingScenario(
        string $status,
        string $detailStatus,
        float $amountDue,
        string $facilityStatus,
        ?string $checkInDate = null,
        ?string $checkOutDate = null,
    ): array {
        $facilityId = $this->createFacility($facilityStatus);
        $bookingId = $this->createBooking(
            $this->createGuest(),
            $status,
            $amountDue,
        );

        $detailId = $this->createBookingDetail(
            $bookingId,
            $facilityId,
            $detailStatus,
            $checkInDate,
            $checkOutDate,
        );

        return [$bookingId, $detailId, $facilityId];
    }

    private function createReservationScenario(
        string $status,
        float $amountDue,
        float $totalPrice = 1000.00,
        int $totalGuestCount = 4,
        int $extraGuestCount = 0,
        bool $withDiscount = false,
        bool $withExtraGuest = false,
    ): array {
        $guestId = $this->createGuest();
        $facilityId = $this->createFacility('Available');
        $checkInDate = now()->addDays(5)->toDateString();
        $checkOutDate = now()->addDays(6)->toDateString();

        DB::table('tbl_facility_price')->insert([
            'facility_id' => $facilityId,
            'rate_type' => 'Overnight',
            'facility_price' => 1000.00,
        ]);

        $discountId = null;

        if ($withDiscount) {
            $discountId = DB::table('tbl_discount')
                ->insertGetId([
                    'discount_name' => 'Test 10%',
                    'discount_amount' => 0.10,
                    'app_to_adult' => false,
                    'app_to_children' => false,
                    'app_to_SC_PWD' => false,
                    'app_to_cottage' => false,
                    'app_to_room' => true,
                    'app_to_function_hall' => false,
                    'discount_start' =>
                        now()->subDay()->toDateString(),
                    'discount_end' =>
                        now()->addMonth()->toDateString(),
                    'status' => 'Active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $payload = [
            'r_ref_no' => 'R'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'reservation_date' => now()->toDateString(),
            'total_price' => $totalPrice,
            'amount_due' => $amountDue,
            'no_of_extra_guests' => $extraGuestCount,
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
            $payload['total_guest_count'] = $totalGuestCount;
        }

        $reservationId = DB::table('tbl_reservation')
            ->insertGetId($payload);

        DB::table('tbl_reservation_details')->insert([
            'reservation_id' => $reservationId,
            'facility_id' => $facilityId,
            'rate_type' => 'Overnight',
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'discount_id' => $discountId,
        ]);

        if ($withExtraGuest) {
            DB::table('tbl_reservation_extra_guests')->insert([
                'reservation_id' => $reservationId,
                'first_name' => 'Extra',
                'middle_name' => null,
                'last_name' => 'Guest',
            ]);
        }

        return [
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'facility_id' => $facilityId,
            'discount_id' => $discountId,
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
        ];
    }

    private function createBooking(
        int $guestId,
        string $status,
        float $amountDue,
    ): int {
        $payload = [
            'b_ref_no' => 'B'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_price' => 1000.00,
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

    private function createBookingDetail(
        int $bookingId,
        int $facilityId,
        string $status,
        ?string $checkInDate = null,
        ?string $checkOutDate = null,
    ): int {
        return DB::table('tbl_booking_details')
            ->insertGetId([
                'booking_id' => $bookingId,
                'facility_id' => $facilityId,
                'rate_type' => 'Overnight',
                'check_in_date' =>
                    $checkInDate ?? now()->toDateString(),
                'check_out_date' =>
                    $checkOutDate
                    ?? now()->addDay()->toDateString(),
                'check_in_time' =>
                    $status === 'Checked-in'
                        ? now()->format('H:i:s')
                        : null,
                'status' => $status,
                'discount_id' => null,
                'user_id' => null,
            ]);
    }

    private function createFacility(string $status): int
    {
        $facilityTypeId = DB::table('tbl_facility_type')
            ->where('facility_type', 'Room')
            ->value('facility_type_id');

        if ($facilityTypeId === null) {
            $facilityTypeId = DB::table('tbl_facility_type')
                ->insertGetId([
                    'facility_type' => 'Room',
                ]);
        }

        return DB::table('tbl_facility')
            ->insertGetId([
                'facility_name' => 'Room '.uniqid(),
                'facility_type_id' => $facilityTypeId,
                'facility_size' => 'Standard',
                'facility_status' => $status,
                'capacity' => '10',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createGuest(): int
    {
        return DB::table('tbl_guest')->insertGetId([
            'first_name' => 'Workflow',
            'middle_name' => null,
            'last_name' => 'Guest',
            'contact_no' => '09123456789',
            'address_id' => $this->createAddress(),
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

        $username = strtolower(
            str_replace(' ', '_', $roleName),
        ).uniqid();

        return DB::table('tbl_user')->insertGetId([
            'first_name' => 'Test',
            'middle_name' => null,
            'last_name' => str_replace(' ', '', $roleName),
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
        return DB::table('tbl_address')->insertGetId([
            'purok' => 'Purok 1',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'barangay' => 'Test Barangay',
        ]);
    }

    private function createModeOfPayment(string $name): int
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
}

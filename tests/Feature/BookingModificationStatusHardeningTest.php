<?php

namespace Tests\Feature;

use App\Services\BookingAvailabilityService;
use App\Services\BookingWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class BookingModificationStatusHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_transferred_booking_detail_still_blocks_availability(): void
    {
        $facilityId = $this->createFacility('Room A', 1000.00);
        $bookingId = $this->createBooking(
            status: 'Booked',
            amountDue: 0.00,
        );

        $this->createBookingDetail(
            bookingId: $bookingId,
            facilityId: $facilityId,
            status: 'Transferred',
            checkIn: '2026-09-10',
            checkOut: '2026-09-12',
        );

        $this->assertFalse(
            app(BookingAvailabilityService::class)
                ->isFacilityAvailable(
                    $facilityId,
                    '2026-09-11',
                    '2026-09-13',
                ),
        );

        $this->assertTrue(
            app(BookingAvailabilityService::class)
                ->isFacilityAvailable(
                    $facilityId,
                    '2026-09-12',
                    '2026-09-14',
                ),
        );
    }

    public function test_payment_rejected_booking_detail_still_releases_availability(): void
    {
        $facilityId = $this->createFacility('Room B', 1000.00);
        $bookingId = $this->createBooking(
            status: 'Payment Rejected',
            amountDue: 1000.00,
        );

        $this->createBookingDetail(
            bookingId: $bookingId,
            facilityId: $facilityId,
            status: 'Payment Rejected',
            checkIn: '2026-10-10',
            checkOut: '2026-10-12',
        );

        $this->assertTrue(
            app(BookingAvailabilityService::class)
                ->isFacilityAvailable(
                    $facilityId,
                    '2026-10-11',
                    '2026-10-13',
                ),
        );
    }

    public function test_cashier_created_booking_uses_booked_and_verified_status_case(): void
    {
        $facilityId = $this->createFacility('Room C', 1500.00);
        $modeOfPaymentId = $this->createPaymentMode('Cash');
        $userId = $this->createUser();

        $booking = app(BookingWorkflowService::class)
            ->createBooking([
                'first_name' => 'Juan',
                'middle_name' => null,
                'last_name' => 'Dela Cruz',
                'contact_no' => '09123456789',
                'email' => 'juan'.uniqid().'@example.test',
                'province' => 'Sultan Kudarat',
                'city' => 'Tacurong City',
                'barangay' => 'Poblacion',
                'purok' => 'Purok 1',
                'facility_id' => $facilityId,
                'rate_type' => 'Overnight',
                'discount_id' => null,
                'total_guest_count' => 4,
                'extra_guests' => [],
                'check_in_date' => '2026-11-10',
                'check_out_date' => '2026-11-11',
                'check_in_time' => '12:00',
                'mode_of_payment_id' => $modeOfPaymentId,
                'reference_number' => '',
                'payment_amount' => 1500.00,
                'user_id' => $userId,
            ]);

        $this->assertSame('Booked', (string) $booking->status);

        $this->assertDatabaseHas('tbl_payment', [
            'booking_id' => $booking->booking_id,
            'payment_status' => 'Verified',
        ]);
    }

    public function test_booking_reschedule_is_blocked_when_parent_booking_is_payment_rejected(): void
    {
        $facilityId = $this->createFacility('Room D', 1000.00);
        $bookingId = $this->createBooking(
            status: 'Payment Rejected',
            amountDue: 1000.00,
        );

        $detailId = $this->createBookingDetail(
            bookingId: $bookingId,
            facilityId: $facilityId,
            status: 'Booked',
            checkIn: '2026-12-10',
            checkOut: '2026-12-11',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'parent booking is not in an editable state',
        );

        app(BookingWorkflowService::class)
            ->rescheduleBookingDetail(
                $detailId,
                '2026-12-12',
            );
    }

    private function createFacility(string $name, float $price): int
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

        $facilityId = DB::table('tbl_facility')
            ->insertGetId([
                'facility_name' => $name.' '.uniqid(),
                'facility_type_id' => $typeId,
                'facility_size' => 'Standard',
                'facility_status' => 'Available',
                'capacity' => '10',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('tbl_facility_price')
            ->insert([
                'facility_id' => $facilityId,
                'rate_type' => 'Overnight',
                'facility_price' => $price,
            ]);

        return $facilityId;
    }

    private function createBooking(string $status, float $amountDue): int
    {
        $guestId = $this->createGuest();

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

        if (Schema::hasColumn('tbl_booking', 'total_guest_count')) {
            $payload['total_guest_count'] = 4;
        }

        return DB::table('tbl_booking')
            ->insertGetId($payload);
    }

    private function createBookingDetail(
        int $bookingId,
        int $facilityId,
        string $status,
        string $checkIn,
        string $checkOut,
    ): int {
        return DB::table('tbl_booking_details')
            ->insertGetId([
                'booking_id' => $bookingId,
                'facility_id' => $facilityId,
                'rate_type' => 'Overnight',
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'check_in_time' => '12:00:00',
                'status' => $status,
                'discount_id' => null,
                'user_id' => null,
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
                'first_name' => 'Booking',
                'middle_name' => null,
                'last_name' => 'Guest',
                'contact_no' => '09123456789',
                'address_id' => $addressId,
                'email' => uniqid().'@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createUser(): int
    {
        $roleId = DB::table('tbl_role')
            ->where('role_name', 'Cashier')
            ->value('role_id');

        if ($roleId === null) {
            $roleId = DB::table('tbl_role')
                ->insertGetId([
                    'role_name' => 'Cashier',
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
                'first_name' => 'Demo',
                'middle_name' => null,
                'last_name' => 'Cashier',
                'username' => 'cashier'.uniqid(),
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

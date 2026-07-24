<?php

namespace Tests\Feature;

use App\Services\GuestReservationManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class GuestReservationManagementHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_facilities_uses_canonical_availability_rules(): void
    {
        $typeId = $this->createFacilityType();
        $blockedFacilityId = $this->createFacility($typeId, 'Room A');
        $availableFacilityId = $this->createFacility($typeId, 'Room B');

        $bookingId = $this->createBooking('Booked', 0.00);
        $this->createBookingDetail(
            bookingId: $bookingId,
            facilityId: $blockedFacilityId,
            status: 'Transferred',
            checkIn: '2026-08-10',
            checkOut: '2026-08-12',
        );

        $facilities = app(GuestReservationManagementService::class)
            ->availableFacilities(
                $typeId,
                'Overnight',
                '2026-08-11',
                '2026-08-13',
            );

        $this->assertFalse(
            $facilities
                ->pluck('facility_id')
                ->contains($blockedFacilityId),
            'Transferred booking details must still block the target facility.',
        );

        $this->assertTrue(
            $facilities
                ->pluck('facility_id')
                ->contains($availableFacilityId),
        );
    }

    public function test_update_reservation_is_blocked_by_transferred_booking_detail(): void
    {
        Mail::fake();

        $typeId = $this->createFacilityType();
        $currentFacilityId = $this->createFacility($typeId, 'Room C');
        $blockedFacilityId = $this->createFacility($typeId, 'Room D');

        $reservationId = $this->createReservation(
            facilityId: $currentFacilityId,
            status: 'Active',
            amountDue: 1000.00,
            checkIn: '2026-09-10',
            checkOut: '2026-09-12',
        );

        $bookingId = $this->createBooking('Booked', 0.00);
        $this->createBookingDetail(
            bookingId: $bookingId,
            facilityId: $blockedFacilityId,
            status: 'Transferred',
            checkIn: '2026-09-10',
            checkOut: '2026-09-12',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Selected facility is not available',
        );

        app(GuestReservationManagementService::class)
            ->updateReservation(
                $reservationId,
                [
                    'facility_id' => $blockedFacilityId,
                    'rate_type' => 'Overnight',
                    'check_in_date' => '2026-09-10',
                    'check_out_date' => '2026-09-12',
                    'total_guest_count' => 4,
                    'extra_guests' => [],
                ],
            );
    }

    public function test_lowercase_verified_payment_blocks_online_reservation_changes(): void
    {
        $typeId = $this->createFacilityType();
        $facilityId = $this->createFacility($typeId, 'Room E');

        $reservationId = $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            amountDue: 0.00,
            checkIn: '2026-10-10',
            checkOut: '2026-10-12',
        );

        $this->createPaymentForReservation(
            reservationId: $reservationId,
            status: 'verified',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'already has verified payment',
        );

        app(GuestReservationManagementService::class)
            ->cancelReservation(
                $reservationId,
                'Guest requested cancellation.',
            );
    }

    public function test_cancelling_reservation_expires_active_otps(): void
    {
        Mail::fake();

        $typeId = $this->createFacilityType();
        $facilityId = $this->createFacility($typeId, 'Room F');

        $reservationId = $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            amountDue: 1000.00,
            checkIn: '2026-11-10',
            checkOut: '2026-11-12',
        );

        DB::table('tbl_guest_verification_otp')->insert([
            'reservation_id' => $reservationId,
            'email' => 'guest@example.test',
            'purpose' => 'reservation_manage',
            'otp_hash' => 'hashed-value',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'verified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(GuestReservationManagementService::class)
            ->cancelReservation(
                $reservationId,
                'Guest requested cancellation.',
            );

        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' => $reservationId,
            'status' => 'Cancelled',
        ]);

        $expiresAt = DB::table('tbl_guest_verification_otp')
            ->where('reservation_id', $reservationId)
            ->value('expires_at');

        $this->assertTrue(
            now()->greaterThan($expiresAt),
            'Active OTPs should be expired after cancellation.',
        );
    }

    private function createFacilityType(): int
    {
        $existing = DB::table('tbl_facility_type')
            ->where('facility_type', 'Room')
            ->value('facility_type_id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return DB::table('tbl_facility_type')
            ->insertGetId([
                'facility_type' => 'Room',
            ]);
    }

    private function createFacility(int $typeId, string $name): int
    {
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
                'facility_price' => 1000.00,
            ]);

        return $facilityId;
    }

    private function createReservation(
        int $facilityId,
        string $status,
        float $amountDue,
        string $checkIn,
        string $checkOut,
    ): int {
        $guestId = $this->createGuest();

        $payload = [
            'r_ref_no' => 'R'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'reservation_date' => now()->toDateString(),
            'total_price' => 1000.00,
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

        $reservationId = DB::table('tbl_reservation')
            ->insertGetId($payload);

        DB::table('tbl_reservation_details')->insert([
            'reservation_id' => $reservationId,
            'facility_id' => $facilityId,
            'rate_type' => 'Overnight',
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'discount_id' => null,
        ]);

        return $reservationId;
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
        $addressId = DB::table('tbl_address')->insertGetId([
            'purok' => 'Purok 1',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'barangay' => 'Poblacion',
        ]);

        return DB::table('tbl_guest')->insertGetId([
            'first_name' => 'Guest',
            'middle_name' => null,
            'last_name' => 'Reservation',
            'contact_no' => '09123456789',
            'address_id' => $addressId,
            'email' => uniqid().'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPaymentForReservation(
        int $reservationId,
        string $status,
    ): int {
        $modeId = DB::table('tbl_mode_of_payment')
            ->where('mode_of_payment', 'Cash')
            ->value('mode_of_payment_id');

        if ($modeId === null) {
            $modeId = DB::table('tbl_mode_of_payment')
                ->insertGetId([
                    'mode_of_payment' => 'Cash',
                ]);
        }

        $payload = [
            'p_ref_no' => 'P'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'booking_id' => null,
            'reservation_id' => $reservationId,
            'entrance_slip_id' => null,
            'mode_of_payment_id' => $modeId,
            'reference_number' => null,
            'amount_paid' => 1000.00,
            'date_paid' => now()->toDateString(),
            'user_id' => null,
            'payment_status' => $status,
            'verified_by_user_id' => null,
            'verified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('tbl_payment', 'rejection_reason')) {
            $payload['rejection_reason'] = null;
        }

        return DB::table('tbl_payment')
            ->insertGetId($payload);
    }
}

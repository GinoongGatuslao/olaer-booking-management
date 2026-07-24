<?php

namespace Tests\Feature;

use App\Services\BookingAvailabilityService;
use App\Services\ReservationNoShowReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReservationNoShowReleaseHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_past_unpaid_active_reservation_is_marked_no_show(): void
    {
        $facilityId = $this->createFacility();
        $reservationId = $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            checkInDate: '2026-01-10',
            checkOutDate: '2026-01-11',
            amountDue: 1000.00,
        );

        $released = app(ReservationNoShowReleaseService::class)
            ->expirePastUnpaidReservations(
                '2026-01-12',
            );

        $this->assertSame(1, $released);

        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' => $reservationId,
            'status' => 'No-show',
        ]);
    }

    public function test_no_show_release_frees_the_facility_schedule(): void
    {
        $facilityId = $this->createFacility();

        $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            checkInDate: '2026-02-10',
            checkOutDate: '2026-02-12',
            amountDue: 1000.00,
        );

        $this->assertFalse(
            app(BookingAvailabilityService::class)
                ->isFacilityAvailable(
                    $facilityId,
                    '2026-02-11',
                    '2026-02-13',
                ),
        );

        app(ReservationNoShowReleaseService::class)
            ->expirePastUnpaidReservations(
                '2026-02-13',
            );

        $this->assertTrue(
            app(BookingAvailabilityService::class)
                ->isFacilityAvailable(
                    $facilityId,
                    '2026-02-11',
                    '2026-02-13',
                ),
        );
    }

    public function test_today_or_future_reservations_are_not_marked_no_show(): void
    {
        $facilityId = $this->createFacility();

        $todayReservationId = $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            checkInDate: '2026-03-10',
            checkOutDate: '2026-03-11',
            amountDue: 1000.00,
        );

        $futureReservationId = $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            checkInDate: '2026-03-11',
            checkOutDate: '2026-03-12',
            amountDue: 1000.00,
        );

        $released = app(ReservationNoShowReleaseService::class)
            ->expirePastUnpaidReservations(
                '2026-03-10',
            );

        $this->assertSame(0, $released);

        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' => $todayReservationId,
            'status' => 'Active',
        ]);

        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' => $futureReservationId,
            'status' => 'Active',
        ]);
    }

    public function test_reservation_with_verified_payment_is_not_auto_no_showed(): void
    {
        $facilityId = $this->createFacility();

        $reservationId = $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            checkInDate: '2026-04-10',
            checkOutDate: '2026-04-11',
            amountDue: 500.00,
        );

        $this->createPaymentForReservation(
            $reservationId,
            'verified',
        );

        $released = app(ReservationNoShowReleaseService::class)
            ->expirePastUnpaidReservations(
                '2026-04-12',
            );

        $this->assertSame(0, $released);

        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' => $reservationId,
            'status' => 'Active',
        ]);
    }

    public function test_no_show_release_is_idempotent(): void
    {
        $facilityId = $this->createFacility();

        $reservationId = $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            checkInDate: '2026-05-10',
            checkOutDate: '2026-05-11',
            amountDue: 1000.00,
        );

        $service = app(ReservationNoShowReleaseService::class);

        $this->assertSame(
            1,
            $service->expirePastUnpaidReservations('2026-05-12'),
        );

        $this->assertSame(
            0,
            $service->expirePastUnpaidReservations('2026-05-12'),
        );

        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' => $reservationId,
            'status' => 'No-show',
        ]);
    }

    private function createReservation(
        int $facilityId,
        string $status,
        string $checkInDate,
        string $checkOutDate,
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
            'cancellation_reason' => null,
            'cancelled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('tbl_reservation', 'total_guest_count')) {
            $payload['total_guest_count'] = 4;
        }

        $reservationId = DB::table('tbl_reservation')
            ->insertGetId($payload);

        DB::table('tbl_reservation_details')
            ->insert([
                'reservation_id' => $reservationId,
                'facility_id' => $facilityId,
                'rate_type' => 'Overnight',
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'discount_id' => null,
            ]);

        return $reservationId;
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
                'first_name' => 'Reservation',
                'middle_name' => null,
                'last_name' => 'Guest',
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
            'proof_of_payment_path' => null,
            'amount_paid' => 500.00,
            'date_paid' => now()->toDateString(),
            'user_id' => null,
            'payment_status' => $status,
            'verified_by_user_id' => null,
            'verified_at' => now(),
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

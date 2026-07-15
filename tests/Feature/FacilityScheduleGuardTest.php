<?php

namespace Tests\Feature;

use App\Services\BookingAvailabilityService;
use App\Services\FacilityScheduleLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FacilityScheduleGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unavailable_facility_is_not_bookable(): void
    {
        $facilityId = $this->createFacility('Unavailable');

        $this->assertFalse(
            app(BookingAvailabilityService::class)
                ->isFacilityAvailable(
                    $facilityId,
                    '2026-08-10',
                    '2026-08-12',
                ),
        );
    }

    public function test_overlapping_pending_booking_blocks_the_facility(): void
    {
        $facilityId = $this->createFacility('Available');
        $guestId = $this->createGuest();

        [$bookingId, $detailId] = $this->createBookingBlock(
            $guestId,
            $facilityId,
            '2026-08-10',
            '2026-08-12',
            'Pending Verification',
            'Pending Verification',
        );

        $service = app(BookingAvailabilityService::class);

        $this->assertFalse(
            $service->isFacilityAvailable(
                $facilityId,
                '2026-08-11',
                '2026-08-13',
            ),
        );

        DB::table('tbl_booking')
            ->where('booking_id', $bookingId)
            ->update(['status' => 'Payment Rejected']);

        DB::table('tbl_booking_details')
            ->where('booking_details_id', $detailId)
            ->update(['status' => 'Payment Rejected']);

        $this->assertTrue(
            $service->isFacilityAvailable(
                $facilityId,
                '2026-08-11',
                '2026-08-13',
            ),
        );
    }

    public function test_active_reservation_blocks_until_it_is_converted(): void
    {
        $facilityId = $this->createFacility('Available');
        $guestId = $this->createGuest();

        [$reservationId] = $this->createReservationBlock(
            $guestId,
            $facilityId,
            '2026-09-01',
            '2026-09-03',
            'Active',
        );

        $service = app(BookingAvailabilityService::class);

        $this->assertFalse(
            $service->isFacilityAvailable(
                $facilityId,
                '2026-09-02',
                '2026-09-04',
            ),
        );

        DB::table('tbl_reservation')
            ->where('reservation_id', $reservationId)
            ->update(['status' => 'Converted']);

        $this->assertTrue(
            $service->isFacilityAvailable(
                $facilityId,
                '2026-09-02',
                '2026-09-04',
            ),
        );
    }

    public function test_adjacent_date_ranges_do_not_overlap(): void
    {
        $facilityId = $this->createFacility('Available');
        $guestId = $this->createGuest();

        $this->createBookingBlock(
            $guestId,
            $facilityId,
            '2026-10-10',
            '2026-10-12',
            'Booked',
            'Booked',
        );

        $this->assertTrue(
            app(BookingAvailabilityService::class)
                ->isFacilityAvailable(
                    $facilityId,
                    '2026-10-12',
                    '2026-10-14',
                ),
        );
    }

    public function test_schedule_lock_normalizes_duplicate_facility_ids(): void
    {
        $firstId = $this->createFacility('Available');
        $secondId = $this->createFacility('Available');

        DB::transaction(function () use (
            $firstId,
            $secondId,
        ): void {
            $locked = app(
                FacilityScheduleLockService::class,
            )->lockMany([
                $secondId,
                $firstId,
                $secondId,
            ]);

            $this->assertSame(
                [$firstId, $secondId],
                $locked
                    ->pluck('facility_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all(),
            );
        });
    }

    private function createFacility(string $status): int
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
                'facility_status' => $status,
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
                'barangay' => 'Test Barangay',
            ]);

        return DB::table('tbl_guest')
            ->insertGetId([
                'first_name' => 'Schedule',
                'middle_name' => null,
                'last_name' => 'Tester',
                'contact_no' => '09123456789',
                'address_id' => $addressId,
                'email' => uniqid().'@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createBookingBlock(
        int $guestId,
        int $facilityId,
        string $checkIn,
        string $checkOut,
        string $bookingStatus,
        string $detailStatus,
    ): array {
        $booking = [
            'b_ref_no' => 'B'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_price' => 1000.00,
            'amount_due' => 1000.00,
            'user_id' => null,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'status' => $bookingStatus,
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

        $detailId = DB::table('tbl_booking_details')
            ->insertGetId([
                'booking_id' => $bookingId,
                'facility_id' => $facilityId,
                'rate_type' => 'Overnight',
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'check_in_time' => '12:00:00',
                'status' => $detailStatus,
                'discount_id' => null,
                'user_id' => null,
            ]);

        return [$bookingId, $detailId];
    }

    private function createReservationBlock(
        int $guestId,
        int $facilityId,
        string $checkIn,
        string $checkOut,
        string $status,
    ): array {
        $reservation = [
            'r_ref_no' => 'R'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'reservation_date' => now()->toDateString(),
            'total_price' => 1000.00,
            'amount_due' => 1000.00,
            'no_of_extra_guests' => 0,
            'user_id' => null,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (
            Schema::hasColumn(
                'tbl_reservation',
                'total_guest_count',
            )
        ) {
            $reservation['total_guest_count'] = 4;
        }

        $reservationId = DB::table('tbl_reservation')
            ->insertGetId($reservation);

        $detailId = DB::table('tbl_reservation_details')
            ->insertGetId([
                'reservation_id' => $reservationId,
                'facility_id' => $facilityId,
                'rate_type' => 'Overnight',
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'discount_id' => null,
            ]);

        return [$reservationId, $detailId];
    }
}

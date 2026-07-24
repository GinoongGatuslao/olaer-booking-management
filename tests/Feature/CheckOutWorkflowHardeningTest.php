<?php

namespace Tests\Feature;

use App\Services\CheckOutWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class CheckOutWorkflowHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_cashier_can_check_out_a_booking_detail(): void
    {
        $facilityId = $this->createFacility('Occupied');
        $bookingId = $this->createBooking('Checked-in', 0.00);
        $detailId = $this->createBookingDetail(
            $bookingId,
            $facilityId,
            'Checked-in',
        );

        $cashierId = $this->createUser('Cashier');
        $managerId = $this->createUser('Manager');
        $maintenanceId = $this->createUser('Maintenance Staff');

        $this->createCompletedInspection(
            $bookingId,
            $detailId,
            $facilityId,
            $cashierId,
            $maintenanceId,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Only a Cashier may check out bookings.',
        );

        app(CheckOutWorkflowService::class)
            ->checkOutBookingDetail(
                $detailId,
                $managerId,
            );
    }

    public function test_checkout_is_blocked_when_booking_still_has_balance(): void
    {
        $facilityId = $this->createFacility('Occupied');
        $bookingId = $this->createBooking('Checked-in', 250.00);
        $detailId = $this->createBookingDetail(
            $bookingId,
            $facilityId,
            'Checked-in',
        );

        $cashierId = $this->createUser('Cashier');
        $maintenanceId = $this->createUser('Maintenance Staff');

        $this->createCompletedInspection(
            $bookingId,
            $detailId,
            $facilityId,
            $cashierId,
            $maintenanceId,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'still has an unpaid balance',
        );

        app(CheckOutWorkflowService::class)
            ->checkOutBookingDetail(
                $detailId,
                $cashierId,
            );
    }

    public function test_successful_checkout_releases_facility_and_closes_booking(): void
    {
        $facilityId = $this->createFacility('Occupied');
        $bookingId = $this->createBooking('Checked-in', 0.00);
        $detailId = $this->createBookingDetail(
            $bookingId,
            $facilityId,
            'Checked-in',
        );

        $cashierId = $this->createUser('Cashier');
        $maintenanceId = $this->createUser('Maintenance Staff');

        $this->createCompletedInspection(
            $bookingId,
            $detailId,
            $facilityId,
            $cashierId,
            $maintenanceId,
        );

        app(CheckOutWorkflowService::class)
            ->checkOutBookingDetail(
                $detailId,
                $cashierId,
            );

        $this->assertDatabaseHas('tbl_booking_details', [
            'booking_details_id' => $detailId,
            'status' => 'Checked-out',
            'user_id' => $cashierId,
        ]);

        $this->assertDatabaseHas('tbl_facility', [
            'facility_id' => $facilityId,
            'facility_status' => 'Available',
        ]);

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $bookingId,
            'status' => 'Checked-out',
        ]);
    }

    public function test_facility_stays_occupied_when_another_checked_in_detail_still_uses_it(): void
    {
        $facilityId = $this->createFacility('Occupied');

        $firstBookingId = $this->createBooking('Checked-in', 0.00);
        $firstDetailId = $this->createBookingDetail(
            $firstBookingId,
            $facilityId,
            'Checked-in',
        );

        $secondBookingId = $this->createBooking('Checked-in', 0.00);
        $this->createBookingDetail(
            $secondBookingId,
            $facilityId,
            'Checked-in',
        );

        $cashierId = $this->createUser('Cashier');
        $maintenanceId = $this->createUser('Maintenance Staff');

        $this->createCompletedInspection(
            $firstBookingId,
            $firstDetailId,
            $facilityId,
            $cashierId,
            $maintenanceId,
        );

        app(CheckOutWorkflowService::class)
            ->checkOutBookingDetail(
                $firstDetailId,
                $cashierId,
            );

        $this->assertDatabaseHas('tbl_facility', [
            'facility_id' => $facilityId,
            'facility_status' => 'Occupied',
        ]);

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $firstBookingId,
            'status' => 'Checked-out',
        ]);
    }

    public function test_checkout_requires_completed_inspection_record(): void
    {
        $facilityId = $this->createFacility('Occupied');
        $bookingId = $this->createBooking('Checked-in', 0.00);
        $detailId = $this->createBookingDetail(
            $bookingId,
            $facilityId,
            'Checked-in',
        );

        $cashierId = $this->createUser('Cashier');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'completed maintenance inspection request',
        );

        app(CheckOutWorkflowService::class)
            ->checkOutBookingDetail(
                $detailId,
                $cashierId,
            );
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
    ): int {
        return DB::table('tbl_booking_details')
            ->insertGetId([
                'booking_id' => $bookingId,
                'facility_id' => $facilityId,
                'rate_type' => 'Overnight',
                'check_in_date' => '2026-12-10',
                'check_out_date' => '2026-12-11',
                'check_in_time' => '12:00:00',
                'status' => $status,
                'discount_id' => null,
                'user_id' => null,
            ]);
    }

    private function createCompletedInspection(
        int $bookingId,
        int $bookingDetailsId,
        int $facilityId,
        int $cashierId,
        int $maintenanceId,
    ): void {
        $request = [
            'booking_id' => $bookingId,
            'booking_details_id' => $bookingDetailsId,
            'facility_id' => $facilityId,
            'requested_by_user_id' => $cashierId,
            'assigned_to_user_id' => $maintenanceId,
            'status' => 'Completed',
            'request_notes' => null,
            'requested_at' => now(),
            'accepted_at' => now(),
            'completed_at' => now(),
        ];

        $this->addTimestampsIfPresent(
            'tbl_facility_inspection_request',
            $request,
        );

        $requestId = DB::table('tbl_facility_inspection_request')
            ->insertGetId($request);

        $inspection = [
            'booking_details_id' => $bookingDetailsId,
            'booking_id' => $bookingId,
            'facility_id' => $facilityId,
            'inspected_by_user_id' => $maintenanceId,
            'inspection_status' => 'No Damage',
            'remarks' => 'Ready for checkout.',
            'inspected_at' => now(),
        ];

        if (
            Schema::hasColumn(
                'tbl_facility_inspection',
                'facility_inspection_request_id',
            )
        ) {
            $inspection['facility_inspection_request_id'] = $requestId;
        }

        $this->addTimestampsIfPresent(
            'tbl_facility_inspection',
            $inspection,
        );

        DB::table('tbl_facility_inspection')
            ->insert($inspection);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function addTimestampsIfPresent(
        string $table,
        array &$payload,
    ): void {
        if (Schema::hasColumn($table, 'created_at')) {
            $payload['created_at'] = now();
        }

        if (Schema::hasColumn($table, 'updated_at')) {
            $payload['updated_at'] = now();
        }
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
                'first_name' => 'Checkout',
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
}

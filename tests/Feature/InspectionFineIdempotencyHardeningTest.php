<?php

namespace Tests\Feature;

use App\Services\FacilityInspectionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class InspectionFineIdempotencyHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeating_same_fine_does_not_double_charge_booking(): void
    {
        [$bookingId, $detailId, $facilityId] = $this->checkedInBooking();
        $maintenanceId = $this->createUser('Maintenance Staff');
        $cashierId = $this->createUser('Cashier');
        $amenityId = $this->createAmenity();
        $facilityAmenityId = $this->createFacilityAmenity($facilityId, $amenityId, 2);
        $fineId = $this->createFine($amenityId, 100.00);

        $this->createInspectionRequest(
            $bookingId,
            $detailId,
            $facilityId,
            $cashierId,
            $maintenanceId,
            'In Progress',
        );

        $service = app(FacilityInspectionWorkflowService::class);

        $first = $service->recordFine(
            $detailId,
            $fineId,
            1,
            $maintenanceId,
            'One towel damaged.',
            'facility_amenity',
            $facilityAmenityId,
        );

        $second = $service->recordFine(
            $detailId,
            $fineId,
            1,
            $maintenanceId,
            'Double-click same fine.',
            'facility_amenity',
            $facilityAmenityId,
        );

        $this->assertSame(
            $first->guest_fine_id,
            $second->guest_fine_id,
        );

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $bookingId,
            'amount_due' => 100.00,
            'total_price' => 1100.00,
        ]);

        $this->assertSame(
            1,
            DB::table('tbl_guest_fine')->count(),
        );
    }

    public function test_updating_same_fine_quantity_adds_only_the_delta(): void
    {
        [$bookingId, $detailId, $facilityId] = $this->checkedInBooking();
        $maintenanceId = $this->createUser('Maintenance Staff');
        $cashierId = $this->createUser('Cashier');
        $amenityId = $this->createAmenity();
        $facilityAmenityId = $this->createFacilityAmenity($facilityId, $amenityId, 2);
        $fineId = $this->createFine($amenityId, 100.00);

        $this->createInspectionRequest(
            $bookingId,
            $detailId,
            $facilityId,
            $cashierId,
            $maintenanceId,
            'In Progress',
        );

        $service = app(FacilityInspectionWorkflowService::class);

        $service->recordFine(
            $detailId,
            $fineId,
            1,
            $maintenanceId,
            'One damaged.',
            'facility_amenity',
            $facilityAmenityId,
        );

        $service->recordFine(
            $detailId,
            $fineId,
            2,
            $maintenanceId,
            'Two damaged.',
            'facility_amenity',
            $facilityAmenityId,
        );

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $bookingId,
            'amount_due' => 200.00,
            'total_price' => 1200.00,
        ]);

        $this->assertDatabaseHas('tbl_guest_fine', [
            'booking_id' => $bookingId,
            'booking_details_id' => $detailId,
            'facility_id' => $facilityId,
            'fine_id' => $fineId,
            'item_source' => 'facility_amenity',
            'source_id' => $facilityAmenityId,
            'quantity' => 2,
            'total_charge' => 200.00,
        ]);
    }

    public function test_fine_quantity_cannot_exceed_expected_checklist_quantity(): void
    {
        [$bookingId, $detailId, $facilityId] = $this->checkedInBooking();
        $maintenanceId = $this->createUser('Maintenance Staff');
        $cashierId = $this->createUser('Cashier');
        $amenityId = $this->createAmenity();
        $facilityAmenityId = $this->createFacilityAmenity($facilityId, $amenityId, 1);
        $fineId = $this->createFine($amenityId, 100.00);

        $this->createInspectionRequest(
            $bookingId,
            $detailId,
            $facilityId,
            $cashierId,
            $maintenanceId,
            'In Progress',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Fine quantity cannot be greater than the checklist expected quantity.',
        );

        app(FacilityInspectionWorkflowService::class)
            ->recordFine(
                $detailId,
                $fineId,
                2,
                $maintenanceId,
                'Too many.',
                'facility_amenity',
                $facilityAmenityId,
            );
    }

    public function test_pending_verification_booking_cannot_be_inspected(): void
    {
        [$bookingId, $detailId, $facilityId] = $this->checkedInBooking(
            bookingStatus: 'Pending Verification',
        );
        $maintenanceId = $this->createUser('Maintenance Staff');
        $cashierId = $this->createUser('Cashier');
        $amenityId = $this->createAmenity();
        $fineId = $this->createFine($amenityId, 100.00);

        $this->createInspectionRequest(
            $bookingId,
            $detailId,
            $facilityId,
            $cashierId,
            $maintenanceId,
            'In Progress',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'This booking can no longer be inspected.',
        );

        app(FacilityInspectionWorkflowService::class)
            ->recordFine(
                $detailId,
                $fineId,
                1,
                $maintenanceId,
            );
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function checkedInBooking(
        string $bookingStatus = 'Checked-in',
    ): array {
        $facilityId = $this->createFacility();
        $guestId = $this->createGuest();

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
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'status' => $bookingStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('tbl_booking', 'total_guest_count')) {
            $payload['total_guest_count'] = 4;
        }

        $bookingId = DB::table('tbl_booking')
            ->insertGetId($payload);

        $detailId = DB::table('tbl_booking_details')
            ->insertGetId([
                'booking_id' => $bookingId,
                'facility_id' => $facilityId,
                'rate_type' => 'Overnight',
                'check_in_date' => '2026-12-10',
                'check_out_date' => '2026-12-11',
                'check_in_time' => '12:00:00',
                'status' => 'Checked-in',
                'discount_id' => null,
                'user_id' => null,
            ]);

        return [$bookingId, $detailId, $facilityId];
    }

    private function createInspectionRequest(
        int $bookingId,
        int $detailId,
        int $facilityId,
        int $cashierId,
        int $maintenanceId,
        string $status,
    ): int {
        $payload = [
            'booking_id' => $bookingId,
            'booking_details_id' => $detailId,
            'facility_id' => $facilityId,
            'requested_by_user_id' => $cashierId,
            'assigned_to_user_id' => $maintenanceId,
            'status' => $status,
            'request_notes' => null,
            'requested_at' => now(),
            'accepted_at' => now(),
            'completed_at' => $status === 'Completed' ? now() : null,
        ];

        $this->addTimestampsIfPresent(
            'tbl_facility_inspection_request',
            $payload,
        );

        return DB::table('tbl_facility_inspection_request')
            ->insertGetId($payload);
    }

    private function createFine(int $amenityId, float $charge): int
    {
        $damageTypeId = DB::table('tbl_damage_type')
            ->insertGetId([
                'damage_type' => 'Damaged',
            ]);

        return DB::table('tbl_fine')
            ->insertGetId([
                'fine_type' => 'Amenity Fine',
                'amenity_id' => $amenityId,
                'damage_type_id' => $damageTypeId,
                'situational_fine' => 'Damaged item',
                'situational_fine_description' => 'Item damaged by guest',
                'fine_charge' => $charge,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createAmenity(): int
    {
        $nameId = DB::table('tbl_amenity_name')
            ->insertGetId([
                'amenity_name' => 'Towel '.uniqid(),
            ]);

        return DB::table('tbl_amenity')
            ->insertGetId([
                'amenity_name_id' => $nameId,
                'amenity_description' => 'Room towel',
                'amenity_type' => 'Rentable',
                'amenity_price' => 100.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createFacilityAmenity(
        int $facilityId,
        int $amenityId,
        int $quantity,
    ): int {
        return DB::table('tbl_facility_amenities')
            ->insertGetId([
                'facility_id' => $facilityId,
                'amenity_id' => $amenityId,
                'amenity_quantity' => $quantity,
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
                'facility_status' => 'Occupied',
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
                'first_name' => 'Inspection',
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
}

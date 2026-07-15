<?php

namespace Tests\Feature;

use App\Models\FacilityInspection;
use App\Services\CheckInWorkflowService;
use App\Services\CheckOutInspectionRequestService;
use App\Services\CheckOutWorkflowService;
use App\Services\FacilityInspectionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class CoreWorkflowGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaid_booking_cannot_be_checked_in(): void
    {
        $cashierId = $this->createUser(
            roleName: 'Cashier',
            username: 'cashier_unpaid',
        );

        [$bookingId, $detailId, $facilityId] =
            $this->createBookingScenario(
                cashierId: $cashierId,
                bookingStatus: 'Booked',
                detailStatus: 'Booked',
                amountDue: 500.00,
                facilityStatus: 'Available',
            );

        try {
            app(CheckInWorkflowService::class)
                ->checkInBookingDetail(
                    $detailId,
                    $cashierId,
                );

            $this->fail(
                'Unpaid booking was checked in.',
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The booking must be fully paid before check-in.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $bookingId,
            'status' => 'Booked',
        ]);

        $this->assertDatabaseHas('tbl_booking_details', [
            'booking_details_id' => $detailId,
            'status' => 'Booked',
        ]);

        $this->assertDatabaseHas('tbl_facility', [
            'facility_id' => $facilityId,
            'facility_status' => 'Available',
        ]);
    }

    public function test_paid_booking_check_in_marks_facility_occupied(): void
    {
        $cashierId = $this->createUser(
            roleName: 'Cashier',
            username: 'cashier_paid',
        );

        [$bookingId, $detailId, $facilityId] =
            $this->createBookingScenario(
                cashierId: $cashierId,
                bookingStatus: 'Booked',
                detailStatus: 'Booked',
                amountDue: 0.00,
                facilityStatus: 'Available',
            );

        app(CheckInWorkflowService::class)
            ->checkInBookingDetail(
                $detailId,
                $cashierId,
            );

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $bookingId,
            'status' => 'Checked-in',
        ]);

        $this->assertDatabaseHas('tbl_booking_details', [
            'booking_details_id' => $detailId,
            'status' => 'Checked-in',
            'user_id' => $cashierId,
        ]);

        $this->assertDatabaseHas('tbl_facility', [
            'facility_id' => $facilityId,
            'facility_status' => 'Occupied',
        ]);
    }

    public function test_only_cashier_can_send_inspection_request(): void
    {
        $cashierId = $this->createUser(
            roleName: 'Cashier',
            username: 'cashier_request',
        );

        $maintenanceId = $this->createUser(
            roleName: 'Maintenance Staff',
            username: 'maintenance_wrong_requester',
        );

        [, $detailId] = $this->createBookingScenario(
            cashierId: $cashierId,
            bookingStatus: 'Checked-in',
            detailStatus: 'Checked-in',
            amountDue: 0.00,
            facilityStatus: 'Occupied',
        );

        try {
            app(CheckOutInspectionRequestService::class)
                ->requestInspection(
                    $detailId,
                    $maintenanceId,
                );

            $this->fail(
                'A non-cashier sent an inspection request.',
            );
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Only cashiers can send facility inspection requests.',
                $exception->errors()['inspection'][0],
            );
        }

        $this->assertDatabaseCount(
            'tbl_facility_inspection_request',
            0,
        );
    }

    public function test_inspection_request_is_idempotent_for_active_request(): void
    {
        $cashierId = $this->createUser(
            roleName: 'Cashier',
            username: 'cashier_idempotent',
        );

        [, $detailId] = $this->createBookingScenario(
            cashierId: $cashierId,
            bookingStatus: 'Checked-in',
            detailStatus: 'Checked-in',
            amountDue: 0.00,
            facilityStatus: 'Occupied',
        );

        $service = app(
            CheckOutInspectionRequestService::class,
        );

        $first = $service->requestInspection(
            $detailId,
            $cashierId,
            'First request',
        );

        $second = $service->requestInspection(
            $detailId,
            $cashierId,
            'Duplicate click',
        );

        $this->assertSame(
            $first->facility_inspection_request_id,
            $second->facility_inspection_request_id,
        );

        $this->assertDatabaseCount(
            'tbl_facility_inspection_request',
            1,
        );
    }

    public function test_in_progress_inspection_cannot_be_stolen_by_another_staff_member(): void
    {
        $cashierId = $this->createUser(
            roleName: 'Cashier',
            username: 'cashier_assignment',
        );

        $maintenanceOneId = $this->createUser(
            roleName: 'Maintenance Staff',
            username: 'maintenance_one',
        );

        $maintenanceTwoId = $this->createUser(
            roleName: 'Maintenance Staff',
            username: 'maintenance_two',
        );

        [, $detailId] = $this->createBookingScenario(
            cashierId: $cashierId,
            bookingStatus: 'Checked-in',
            detailStatus: 'Checked-in',
            amountDue: 0.00,
            facilityStatus: 'Occupied',
        );

        $service = app(
            CheckOutInspectionRequestService::class,
        );

        $request = $service->requestInspection(
            $detailId,
            $cashierId,
        );

        $service->acceptRequest(
            (int) $request->facility_inspection_request_id,
            $maintenanceOneId,
        );

        try {
            $service->acceptRequest(
                (int) $request->facility_inspection_request_id,
                $maintenanceTwoId,
            );

            $this->fail(
                'Another maintenance staff member stole the assignment.',
            );
        } catch (ValidationException $exception) {
            $this->assertSame(
                'This inspection request is already assigned to another maintenance staff member.',
                $exception->errors()['inspection'][0],
            );
        }

        $this->assertDatabaseHas(
            'tbl_facility_inspection_request',
            [
                'facility_inspection_request_id' =>
                    $request->facility_inspection_request_id,
                'status' => 'In Progress',
                'assigned_to_user_id' =>
                    $maintenanceOneId,
            ],
        );
    }

    public function test_only_assigned_maintenance_can_complete_inspection(): void
    {
        $cashierId = $this->createUser(
            roleName: 'Cashier',
            username: 'cashier_inspection',
        );

        $maintenanceOneId = $this->createUser(
            roleName: 'Maintenance Staff',
            username: 'maintenance_assigned',
        );

        $maintenanceTwoId = $this->createUser(
            roleName: 'Maintenance Staff',
            username: 'maintenance_unassigned',
        );

        [, $detailId] = $this->createBookingScenario(
            cashierId: $cashierId,
            bookingStatus: 'Checked-in',
            detailStatus: 'Checked-in',
            amountDue: 0.00,
            facilityStatus: 'Occupied',
        );

        $requestService = app(
            CheckOutInspectionRequestService::class,
        );

        $request = $requestService->requestInspection(
            $detailId,
            $cashierId,
        );

        $requestService->acceptRequest(
            (int) $request->facility_inspection_request_id,
            $maintenanceOneId,
        );

        try {
            app(FacilityInspectionWorkflowService::class)
                ->markNoDamage(
                    $detailId,
                    $maintenanceTwoId,
                    'Unauthorized attempt',
                );

            $this->fail(
                'Unassigned maintenance staff completed the inspection.',
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Only the assigned maintenance staff member can complete this inspection.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount(
            'tbl_facility_inspection',
            0,
        );

        app(FacilityInspectionWorkflowService::class)
            ->markNoDamage(
                $detailId,
                $maintenanceOneId,
                'All items complete.',
            );

        $this->assertDatabaseHas(
            'tbl_facility_inspection',
            [
                'booking_details_id' => $detailId,
                'inspected_by_user_id' =>
                    $maintenanceOneId,
                'inspection_status' => 'Cleared',
            ],
        );

        $this->assertDatabaseHas(
            'tbl_facility_inspection_request',
            [
                'facility_inspection_request_id' =>
                    $request->facility_inspection_request_id,
                'status' => 'Completed',
                'assigned_to_user_id' =>
                    $maintenanceOneId,
            ],
        );
    }

    public function test_checkout_requires_completed_inspection_and_zero_balance(): void
    {
        $cashierId = $this->createUser(
            roleName: 'Cashier',
            username: 'cashier_checkout',
        );

        $maintenanceId = $this->createUser(
            roleName: 'Maintenance Staff',
            username: 'maintenance_checkout',
        );

        [$bookingId, $detailId, $facilityId] =
            $this->createBookingScenario(
                cashierId: $cashierId,
                bookingStatus: 'Checked-in',
                detailStatus: 'Checked-in',
                amountDue: 0.00,
                facilityStatus: 'Occupied',
            );

        $checkout = app(
            CheckOutWorkflowService::class,
        );

        try {
            $checkout->checkOutBookingDetail(
                $detailId,
                $cashierId,
            );

            $this->fail(
                'Checkout succeeded without a completed inspection.',
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'A completed maintenance inspection request is required before check-out.',
                $exception->getMessage(),
            );
        }

        $requestService = app(
            CheckOutInspectionRequestService::class,
        );

        $request = $requestService->requestInspection(
            $detailId,
            $cashierId,
        );

        $requestService->acceptRequest(
            (int) $request->facility_inspection_request_id,
            $maintenanceId,
        );

        app(FacilityInspectionWorkflowService::class)
            ->markNoDamage(
                $detailId,
                $maintenanceId,
                'Cleared for checkout.',
            );

        DB::table('tbl_booking')
            ->where('booking_id', $bookingId)
            ->update(['amount_due' => 250.00]);

        try {
            $checkout->checkOutBookingDetail(
                $detailId,
                $cashierId,
            );

            $this->fail(
                'Checkout succeeded with an unpaid balance.',
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'This guest still has an unpaid balance. Settle the bill before check-out.',
                $exception->getMessage(),
            );
        }

        DB::table('tbl_booking')
            ->where('booking_id', $bookingId)
            ->update(['amount_due' => 0.00]);

        $checkout->checkOutBookingDetail(
            $detailId,
            $cashierId,
        );

        $this->assertDatabaseHas('tbl_booking_details', [
            'booking_details_id' => $detailId,
            'status' => 'Checked-out',
        ]);

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $bookingId,
            'status' => 'Checked-out',
            'amount_due' => 0.00,
        ]);

        $this->assertDatabaseHas('tbl_facility', [
            'facility_id' => $facilityId,
            'facility_status' => 'Available',
        ]);
    }

    private function createBookingScenario(
        int $cashierId,
        string $bookingStatus,
        string $detailStatus,
        float $amountDue,
        string $facilityStatus,
    ): array {
        $facilityId = $this->createFacility(
            type: 'Room',
            capacity: 10,
            status: $facilityStatus,
        );

        $guestAddressId = $this->createAddress();

        $guestId = DB::table('tbl_guest')->insertGetId([
            'first_name' => 'Test',
            'middle_name' => null,
            'last_name' => 'Guest',
            'contact_no' => '09123456789',
            'address_id' => $guestAddressId,
            'email' => 'guest'.uniqid().'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bookingData = [
            'b_ref_no' => 'B'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_price' => 1000.00,
            'amount_due' => $amountDue,
            'user_id' => $cashierId,
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
            $bookingData['total_guest_count'] = 4;
        }

        $bookingId = DB::table('tbl_booking')
            ->insertGetId($bookingData);

        $detailData = [
            'booking_id' => $bookingId,
            'facility_id' => $facilityId,
            'rate_type' => 'Overnight',
            'check_in_date' => now()->toDateString(),
            'check_out_date' =>
                now()->addDay()->toDateString(),
            'check_in_time' => $detailStatus === 'Checked-in'
                ? now()->format('H:i:s')
                : null,
            'status' => $detailStatus,
            'discount_id' => null,
            'user_id' => $cashierId,
        ];

        $detailId = DB::table('tbl_booking_details')
            ->insertGetId($detailData);

        return [
            $bookingId,
            $detailId,
            $facilityId,
        ];
    }

    private function createFacility(
        string $type,
        int $capacity,
        string $status,
    ): int {
        $facilityTypeId = DB::table('tbl_facility_type')
            ->where('facility_type', $type)
            ->value('facility_type_id');

        if ($facilityTypeId === null) {
            $facilityTypeId = DB::table(
                'tbl_facility_type',
            )->insertGetId([
                'facility_type' => $type,
            ]);
        }

        return DB::table('tbl_facility')
            ->insertGetId([
                'facility_name' =>
                    $type.' '.uniqid(),
                'facility_type_id' => $facilityTypeId,
                'facility_size' => 'Standard',
                'facility_status' => $status,
                'capacity' => (string) $capacity,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createUser(
        string $roleName,
        string $username,
    ): int {
        $roleId = DB::table('tbl_role')
            ->where('role_name', $roleName)
            ->value('role_id');

        if ($roleId === null) {
            $roleId = DB::table('tbl_role')
                ->insertGetId([
                    'role_name' => $roleName,
                ]);
        }

        $addressId = $this->createAddress();

        return DB::table('tbl_user')->insertGetId([
            'first_name' => 'Test',
            'middle_name' => null,
            'last_name' => str_replace(
                ' ',
                '',
                $roleName,
            ),
            'username' => $username,
            'password' => Hash::make('password'),
            'email' => $username.'@example.test',
            'contact_no' => '09999999999',
            'status' => 'Active',
            'address_id' => $addressId,
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

<?php

namespace Tests\Feature;

use App\Services\CheckOutInspectionRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CheckOutInspectionRequestHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_maintenance_staff_can_complete_request(): void
    {
        $cashierId = $this->createUser(
            'Cashier',
            'cashier_complete_role',
        );

        $maintenanceId = $this->createUser(
            'Maintenance Staff',
            'maintenance_complete_role',
        );

        $managerId = $this->createUser(
            'Manager',
            'manager_complete_role',
        );

        $scenario = $this->createBookingScenario(
            $cashierId,
        );

        $request = $this->requestAndAccept(
            $scenario['booking_details_id'],
            $cashierId,
            $maintenanceId,
        );

        $this->createInspection(
            $scenario,
            $maintenanceId,
        );

        $this->assertInspectionValidation(
            function () use (
                $scenario,
                $managerId,
            ): void {
                app(
                    CheckOutInspectionRequestService::class,
                )->markLatestRequestCompleted(
                    $scenario['booking_details_id'],
                    $managerId,
                );
            },
            'Only maintenance staff can complete facility inspection requests.',
        );

        $this->assertDatabaseHas(
            'tbl_facility_inspection_request',
            [
                'facility_inspection_request_id' =>
                    $request->facility_inspection_request_id,
                'status' => 'In Progress',
                'assigned_to_user_id' =>
                    $maintenanceId,
                'completed_at' => null,
            ],
        );
    }

    public function test_pending_request_cannot_be_completed_before_acceptance(): void
    {
        $cashierId = $this->createUser(
            'Cashier',
            'cashier_pending_complete',
        );

        $maintenanceId = $this->createUser(
            'Maintenance Staff',
            'maintenance_pending_complete',
        );

        $scenario = $this->createBookingScenario(
            $cashierId,
        );

        $request = app(
            CheckOutInspectionRequestService::class,
        )->requestInspection(
            $scenario['booking_details_id'],
            $cashierId,
        );

        $this->createInspection(
            $scenario,
            $maintenanceId,
        );

        $this->assertInspectionValidation(
            function () use (
                $scenario,
                $maintenanceId,
            ): void {
                app(
                    CheckOutInspectionRequestService::class,
                )->markLatestRequestCompleted(
                    $scenario['booking_details_id'],
                    $maintenanceId,
                );
            },
            'Accept the inspection request before completing it.',
        );

        $this->assertDatabaseHas(
            'tbl_facility_inspection_request',
            [
                'facility_inspection_request_id' =>
                    $request->facility_inspection_request_id,
                'status' => 'Pending',
                'assigned_to_user_id' => null,
                'completed_at' => null,
            ],
        );
    }

    public function test_unassigned_maintenance_cannot_complete_request(): void
    {
        $cashierId = $this->createUser(
            'Cashier',
            'cashier_wrong_assignee',
        );

        $maintenanceOneId = $this->createUser(
            'Maintenance Staff',
            'maintenance_assigned_completion',
        );

        $maintenanceTwoId = $this->createUser(
            'Maintenance Staff',
            'maintenance_unassigned_completion',
        );

        $scenario = $this->createBookingScenario(
            $cashierId,
        );

        $request = $this->requestAndAccept(
            $scenario['booking_details_id'],
            $cashierId,
            $maintenanceOneId,
        );

        $this->createInspection(
            $scenario,
            $maintenanceTwoId,
        );

        $this->assertInspectionValidation(
            function () use (
                $scenario,
                $maintenanceTwoId,
            ): void {
                app(
                    CheckOutInspectionRequestService::class,
                )->markLatestRequestCompleted(
                    $scenario['booking_details_id'],
                    $maintenanceTwoId,
                );
            },
            'Only the assigned maintenance staff member can complete this inspection request.',
        );

        $this->assertDatabaseHas(
            'tbl_facility_inspection_request',
            [
                'facility_inspection_request_id' =>
                    $request->facility_inspection_request_id,
                'status' => 'In Progress',
                'assigned_to_user_id' =>
                    $maintenanceOneId,
                'completed_at' => null,
            ],
        );
    }

    public function test_request_cannot_complete_without_inspection_result(): void
    {
        $cashierId = $this->createUser(
            'Cashier',
            'cashier_missing_inspection',
        );

        $maintenanceId = $this->createUser(
            'Maintenance Staff',
            'maintenance_missing_inspection',
        );

        $scenario = $this->createBookingScenario(
            $cashierId,
        );

        $request = $this->requestAndAccept(
            $scenario['booking_details_id'],
            $cashierId,
            $maintenanceId,
        );

        $this->assertInspectionValidation(
            function () use (
                $scenario,
                $maintenanceId,
            ): void {
                app(
                    CheckOutInspectionRequestService::class,
                )->markLatestRequestCompleted(
                    $scenario['booking_details_id'],
                    $maintenanceId,
                );
            },
            'The assigned maintenance staff member must record the facility inspection result before completing the request.',
        );

        $this->assertDatabaseHas(
            'tbl_facility_inspection_request',
            [
                'facility_inspection_request_id' =>
                    $request->facility_inspection_request_id,
                'status' => 'In Progress',
                'completed_at' => null,
            ],
        );
    }

    public function test_assigned_staff_must_own_recorded_inspection(): void
    {
        $cashierId = $this->createUser(
            'Cashier',
            'cashier_inspector_match',
        );

        $maintenanceOneId = $this->createUser(
            'Maintenance Staff',
            'maintenance_assigned_inspector',
        );

        $maintenanceTwoId = $this->createUser(
            'Maintenance Staff',
            'maintenance_wrong_inspector',
        );

        $scenario = $this->createBookingScenario(
            $cashierId,
        );

        $request = $this->requestAndAccept(
            $scenario['booking_details_id'],
            $cashierId,
            $maintenanceOneId,
        );

        $this->createInspection(
            $scenario,
            $maintenanceTwoId,
        );

        $this->assertInspectionValidation(
            function () use (
                $scenario,
                $maintenanceOneId,
            ): void {
                app(
                    CheckOutInspectionRequestService::class,
                )->markLatestRequestCompleted(
                    $scenario['booking_details_id'],
                    $maintenanceOneId,
                );
            },
            'The assigned maintenance staff member must record the facility inspection result before completing the request.',
        );

        $this->assertDatabaseHas(
            'tbl_facility_inspection_request',
            [
                'facility_inspection_request_id' =>
                    $request->facility_inspection_request_id,
                'status' => 'In Progress',
                'completed_at' => null,
            ],
        );
    }

    public function test_repeated_acceptance_is_idempotent(): void
    {
        Carbon::setTestNow(
            '2026-08-10 10:00:00',
        );

        $cashierId = $this->createUser(
            'Cashier',
            'cashier_accept_idempotent',
        );

        $maintenanceId = $this->createUser(
            'Maintenance Staff',
            'maintenance_accept_idempotent',
        );

        $scenario = $this->createBookingScenario(
            $cashierId,
        );

        $service = app(
            CheckOutInspectionRequestService::class,
        );

        $request = $service->requestInspection(
            $scenario['booking_details_id'],
            $cashierId,
        );

        $first = $service->acceptRequest(
            (int) $request
                ->facility_inspection_request_id,
            $maintenanceId,
        );

        $acceptedAt = $first->accepted_at
            ->toDateTimeString();

        Carbon::setTestNow(
            '2026-08-10 11:00:00',
        );

        $second = $service->acceptRequest(
            (int) $request
                ->facility_inspection_request_id,
            $maintenanceId,
        );

        $this->assertSame(
            $first->facility_inspection_request_id,
            $second->facility_inspection_request_id,
        );

        $this->assertSame(
            $acceptedAt,
            $second->accepted_at->toDateTimeString(),
        );

        $this->assertSame(
            'In Progress',
            $second->status,
        );

        $this->assertDatabaseCount(
            'tbl_facility_inspection_request',
            1,
        );
    }

    public function test_repeated_completion_is_idempotent(): void
    {
        Carbon::setTestNow(
            '2026-08-11 09:00:00',
        );

        $cashierId = $this->createUser(
            'Cashier',
            'cashier_complete_idempotent',
        );

        $maintenanceId = $this->createUser(
            'Maintenance Staff',
            'maintenance_complete_idempotent',
        );

        $scenario = $this->createBookingScenario(
            $cashierId,
        );

        $service = app(
            CheckOutInspectionRequestService::class,
        );

        $request = $this->requestAndAccept(
            $scenario['booking_details_id'],
            $cashierId,
            $maintenanceId,
        );

        $this->createInspection(
            $scenario,
            $maintenanceId,
        );

        Carbon::setTestNow(
            '2026-08-11 10:00:00',
        );

        $first =
            $service->markLatestRequestCompleted(
                $scenario['booking_details_id'],
                $maintenanceId,
            );

        $completedAt = $first->completed_at
            ->toDateTimeString();

        Carbon::setTestNow(
            '2026-08-11 11:00:00',
        );

        $second =
            $service->markLatestRequestCompleted(
                $scenario['booking_details_id'],
                $maintenanceId,
            );

        $this->assertSame(
            $request->facility_inspection_request_id,
            $second->facility_inspection_request_id,
        );

        $this->assertSame(
            $completedAt,
            $second->completed_at->toDateTimeString(),
        );

        $this->assertSame(
            'Completed',
            $second->status,
        );

        $this->assertDatabaseCount(
            'tbl_facility_inspection_request',
            1,
        );

        $this->assertDatabaseCount(
            'tbl_facility_inspection',
            1,
        );
    }

    private function requestAndAccept(
        int $bookingDetailsId,
        int $cashierId,
        int $maintenanceId,
    ) {
        $service = app(
            CheckOutInspectionRequestService::class,
        );

        $request = $service->requestInspection(
            $bookingDetailsId,
            $cashierId,
        );

        return $service->acceptRequest(
            (int) $request
                ->facility_inspection_request_id,
            $maintenanceId,
        );
    }

    /**
     * @param callable(): void $callback
     */
    private function assertInspectionValidation(
        callable $callback,
        string $expectedMessage,
    ): void {
        try {
            $callback();

            $this->fail(
                'Expected inspection validation failure.',
            );
        } catch (ValidationException $exception) {
            $this->assertSame(
                $expectedMessage,
                $exception->errors()['inspection'][0],
            );
        }
    }

    /**
     * @return array{
     *   booking_id:int,
     *   booking_details_id:int,
     *   facility_id:int
     * }
     */
    private function createBookingScenario(
        int $cashierId,
    ): array {
        $facilityId = $this->createFacility();
        $guestId = $this->createGuest();

        $bookingData = [
            'b_ref_no' => 'B'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_price' => 1000.00,
            'amount_due' => 0.00,
            'user_id' => $cashierId,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'status' => 'Checked-in',
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

        $detailId = DB::table(
            'tbl_booking_details',
        )->insertGetId([
            'booking_id' => $bookingId,
            'facility_id' => $facilityId,
            'rate_type' => 'Overnight',
            'check_in_date' =>
                now()->toDateString(),
            'check_out_date' =>
                now()->addDay()->toDateString(),
            'check_in_time' =>
                now()->format('H:i:s'),
            'status' => 'Checked-in',
            'discount_id' => null,
            'user_id' => $cashierId,
        ]);

        return [
            'booking_id' => $bookingId,
            'booking_details_id' => $detailId,
            'facility_id' => $facilityId,
        ];
    }

    /**
     * @param array{
     *   booking_id:int,
     *   booking_details_id:int,
     *   facility_id:int
     * } $scenario
     */
    private function createInspection(
        array $scenario,
        int $maintenanceId,
    ): int {
        return DB::table(
            'tbl_facility_inspection',
        )->insertGetId([
            'booking_details_id' =>
                $scenario['booking_details_id'],
            'booking_id' =>
                $scenario['booking_id'],
            'facility_id' =>
                $scenario['facility_id'],
            'inspected_by_user_id' =>
                $maintenanceId,
            'inspection_status' => 'Cleared',
            'remarks' => 'Inspection recorded.',
            'inspected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createFacility(): int
    {
        $facilityTypeId = DB::table(
            'tbl_facility_type',
        )
            ->where('facility_type', 'Room')
            ->value('facility_type_id');

        if ($facilityTypeId === null) {
            $facilityTypeId = DB::table(
                'tbl_facility_type',
            )->insertGetId([
                'facility_type' => 'Room',
            ]);
        }

        return DB::table('tbl_facility')
            ->insertGetId([
                'facility_name' =>
                    'Room '.uniqid(),
                'facility_type_id' =>
                    $facilityTypeId,
                'facility_size' => 'Standard',
                'facility_status' => 'Occupied',
                'capacity' => '10',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createGuest(): int
    {
        return DB::table('tbl_guest')
            ->insertGetId([
                'first_name' => 'Inspection',
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

        return DB::table('tbl_user')
            ->insertGetId([
                'first_name' => 'Test',
                'middle_name' => null,
                'last_name' =>
                    str_replace(' ', '', $roleName),
                'username' => $username,
                'password' =>
                    Hash::make('password'),
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

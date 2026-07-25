<?php

namespace Tests\Feature;

use App\Services\AmenityRequestWorkflowService;
use App\Services\CheckOutInspectionRequestService;
use App\Services\CheckOutWorkflowService;
use App\Services\FacilityInspectionWorkflowService;
use App\Services\PaymentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class OperationalCheckoutEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_inspection_flow_completes_checkout(): void
    {
        $cashierId = $this->createUser(
            'Cashier',
            'cashier_clean_checkout',
        );

        $maintenanceId = $this->createUser(
            'Maintenance Staff',
            'maintenance_clean_checkout',
        );

        $scenario = $this->createCheckedInBooking(
            $cashierId,
        );

        $inspectionRequest = app(
            CheckOutInspectionRequestService::class,
        )->requestInspection(
            $scenario['booking_details_id'],
            $cashierId,
            'Guest is ready to check out.',
        );

        app(
            CheckOutInspectionRequestService::class,
        )->acceptRequest(
            (int) $inspectionRequest
                ->facility_inspection_request_id,
            $maintenanceId,
        );

        app(
            FacilityInspectionWorkflowService::class,
        )->markNoDamage(
            $scenario['booking_details_id'],
            $maintenanceId,
            'Facility and included items are complete.',
        );

        app(CheckOutWorkflowService::class)
            ->checkOutBookingDetail(
                $scenario['booking_details_id'],
                $cashierId,
            );

        $this->assertDatabaseHas(
            'tbl_facility_inspection_request',
            [
                'facility_inspection_request_id' =>
                    $inspectionRequest
                        ->facility_inspection_request_id,
                'status' => 'Completed',
                'assigned_to_user_id' =>
                    $maintenanceId,
            ],
        );

        $this->assertDatabaseHas(
            'tbl_facility_inspection',
            [
                'booking_details_id' =>
                    $scenario['booking_details_id'],
                'booking_id' =>
                    $scenario['booking_id'],
                'facility_id' =>
                    $scenario['facility_id'],
                'inspected_by_user_id' =>
                    $maintenanceId,
                'inspection_status' => 'Cleared',
            ],
        );

        $this->assertCheckedOutState($scenario);
    }

    public function test_delivered_amenity_is_paid_during_final_checkout(): void
    {
        $cashierId = $this->createUser(
            'Cashier',
            'cashier_amenity_checkout',
        );

        $maintenanceId = $this->createUser(
            'Maintenance Staff',
            'maintenance_amenity_checkout',
        );

        $scenario = $this->createCheckedInBooking(
            $cashierId,
        );

        $amenityId = $this->createAmenity(
            'Extra Mattress',
            150.00,
        );

        $amenityRequest = app(
            AmenityRequestWorkflowService::class,
        )->createBillableRequest([
            'booking_id' =>
                $scenario['booking_id'],
            'facility_id' =>
                $scenario['facility_id'],
            'user_id' => $cashierId,
            'items' => [
                [
                    'amenity_id' => $amenityId,
                    'quantity' => 2,
                ],
            ],
        ]);

        $this->assertSame(
            'Pending',
            $amenityRequest
                ->amenity_request_status,
        );

        $this->assertDatabaseHas(
            'tbl_booking',
            [
                'booking_id' =>
                    $scenario['booking_id'],
                'total_price' => 1300.00,
                'amount_due' => 300.00,
                'status' => 'Checked-in',
            ],
        );

        $acceptedAmenity = app(
            AmenityRequestWorkflowService::class,
        )->acceptRequest(
            (int) $amenityRequest
                ->amenity_request_id,
            $maintenanceId,
        );

        $this->assertSame(
            'Delivering',
            $acceptedAmenity
                ->amenity_request_status,
        );

        $deliveredAmenity = app(
            AmenityRequestWorkflowService::class,
        )->markDelivered(
            (int) $amenityRequest
                ->amenity_request_id,
            $maintenanceId,
        );

        $this->assertSame(
            'Delivered',
            $deliveredAmenity
                ->amenity_request_status,
        );

        $checklist = app(
            FacilityInspectionWorkflowService::class,
        )->checklistFor(
            $scenario['booking_details_id'],
        );

        $requestedItem = collect($checklist)
            ->first(
                fn (array $item): bool =>
                    $item['source']
                        === 'amenity_request'
                    && (int) $item['amenity_id']
                        === $amenityId,
            );

        $this->assertNotNull($requestedItem);
        $this->assertSame(
            2,
            (int) $requestedItem[
                'expected_quantity'
            ],
        );

        $inspectionRequest = app(
            CheckOutInspectionRequestService::class,
        )->requestInspection(
            $scenario['booking_details_id'],
            $cashierId,
            'Inspect delivered amenity before checkout.',
        );

        app(
            CheckOutInspectionRequestService::class,
        )->acceptRequest(
            (int) $inspectionRequest
                ->facility_inspection_request_id,
            $maintenanceId,
        );

        app(
            FacilityInspectionWorkflowService::class,
        )->markNoDamage(
            $scenario['booking_details_id'],
            $maintenanceId,
            'Delivered amenity returned complete.',
        );

        try {
            app(CheckOutWorkflowService::class)
                ->checkOutBookingDetail(
                    $scenario['booking_details_id'],
                    $cashierId,
                );

            $this->fail(
                'Checkout succeeded before the amenity balance was paid.',
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'This guest still has an unpaid balance. Settle the bill before check-out.',
                $exception->getMessage(),
            );
        }

        $payment = app(
            PaymentWorkflowService::class,
        )->recordCashierPayment([
            'target_type' => 'booking',
            'target_id' =>
                $scenario['booking_id'],
            'amount_paid' => 300.00,
            'mode_of_payment_id' =>
                $this->createPaymentMode('Cash'),
            'reference_number' => '',
            'user_id' => $cashierId,
        ]);

        $this->assertSame(
            'Verified',
            $payment->payment_status,
        );

        $this->assertDatabaseHas(
            'tbl_booking',
            [
                'booking_id' =>
                    $scenario['booking_id'],
                'total_price' => 1300.00,
                'amount_due' => 0.00,
                'status' => 'Checked-in',
            ],
        );

        app(CheckOutWorkflowService::class)
            ->checkOutBookingDetail(
                $scenario['booking_details_id'],
                $cashierId,
            );

        $this->assertDatabaseHas(
            'tbl_amenity_request',
            [
                'amenity_request_id' =>
                    $amenityRequest
                        ->amenity_request_id,
                'amenity_request_status' =>
                    'Delivered',
                'assigned_to_user_id' =>
                    $maintenanceId,
            ],
        );

        $this->assertDatabaseHas(
            'tbl_facility_inspection_items',
            [
                'item_source' =>
                    'amenity_request',
                'source_id' =>
                    $requestedItem['source_id'],
                'amenity_id' => $amenityId,
                'expected_quantity' => 2,
                'condition_status' =>
                    'Complete',
                'fine_quantity' => 0,
                'total_charge' => 0.00,
            ],
        );

        $this->assertCheckedOutState($scenario);
    }

    public function test_damage_fine_is_paid_before_checkout(): void
    {
        $cashierId = $this->createUser(
            'Cashier',
            'cashier_fine_checkout',
        );

        $maintenanceId = $this->createUser(
            'Maintenance Staff',
            'maintenance_fine_checkout',
        );

        $scenario = $this->createCheckedInBooking(
            $cashierId,
        );

        $amenityId = $this->createAmenity(
            'Room Towel',
            100.00,
        );

        $facilityAmenityId =
            $this->createFacilityAmenity(
                $scenario['facility_id'],
                $amenityId,
                1,
            );

        $fineId = $this->createFine(
            $amenityId,
            200.00,
        );

        $inspectionRequest = app(
            CheckOutInspectionRequestService::class,
        )->requestInspection(
            $scenario['booking_details_id'],
            $cashierId,
            'Inspect facility for reported damage.',
        );

        app(
            CheckOutInspectionRequestService::class,
        )->acceptRequest(
            (int) $inspectionRequest
                ->facility_inspection_request_id,
            $maintenanceId,
        );

        $guestFine = app(
            FacilityInspectionWorkflowService::class,
        )->recordFine(
            $scenario['booking_details_id'],
            $fineId,
            1,
            $maintenanceId,
            'One room towel was damaged.',
            'facility_amenity',
            $facilityAmenityId,
        );

        $this->assertSame(
            '200.00',
            (string) $guestFine
                ->total_charge,
        );

        $this->assertDatabaseHas(
            'tbl_booking',
            [
                'booking_id' =>
                    $scenario['booking_id'],
                'total_price' => 1200.00,
                'amount_due' => 200.00,
                'status' => 'Checked-in',
            ],
        );

        $this->assertDatabaseHas(
            'tbl_facility_inspection_request',
            [
                'facility_inspection_request_id' =>
                    $inspectionRequest
                        ->facility_inspection_request_id,
                'status' => 'Completed',
                'assigned_to_user_id' =>
                    $maintenanceId,
            ],
        );

        $this->assertDatabaseHas(
            'tbl_facility_inspection',
            [
                'booking_details_id' =>
                    $scenario['booking_details_id'],
                'inspection_status' =>
                    'Damage Found',
                'inspected_by_user_id' =>
                    $maintenanceId,
            ],
        );

        try {
            app(CheckOutWorkflowService::class)
                ->checkOutBookingDetail(
                    $scenario['booking_details_id'],
                    $cashierId,
                );

            $this->fail(
                'Checkout succeeded before the fine was paid.',
            );
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'This guest still has an unpaid balance. Settle the bill before check-out.',
                $exception->getMessage(),
            );
        }

        app(PaymentWorkflowService::class)
            ->recordCashierPayment([
                'target_type' => 'booking',
                'target_id' =>
                    $scenario['booking_id'],
                'amount_paid' => 200.00,
                'mode_of_payment_id' =>
                    $this->createPaymentMode('Cash'),
                'reference_number' => '',
                'user_id' => $cashierId,
            ]);

        app(CheckOutWorkflowService::class)
            ->checkOutBookingDetail(
                $scenario['booking_details_id'],
                $cashierId,
            );

        $this->assertDatabaseHas(
            'tbl_guest_fine',
            [
                'guest_fine_id' =>
                    $guestFine->guest_fine_id,
                'booking_id' =>
                    $scenario['booking_id'],
                'booking_details_id' =>
                    $scenario['booking_details_id'],
                'facility_id' =>
                    $scenario['facility_id'],
                'fine_id' => $fineId,
                'item_source' =>
                    'facility_amenity',
                'source_id' =>
                    $facilityAmenityId,
                'quantity' => 1,
                'total_charge' => 200.00,
            ],
        );

        $this->assertCheckedOutState($scenario);
    }

    /**
     * @param array{
     *   booking_id:int,
     *   booking_details_id:int,
     *   facility_id:int
     * } $scenario
     */
    private function assertCheckedOutState(
        array $scenario,
    ): void {
        $this->assertDatabaseHas(
            'tbl_booking_details',
            [
                'booking_details_id' =>
                    $scenario['booking_details_id'],
                'status' => 'Checked-out',
            ],
        );

        $this->assertDatabaseHas(
            'tbl_booking',
            [
                'booking_id' =>
                    $scenario['booking_id'],
                'status' => 'Checked-out',
                'amount_due' => 0.00,
            ],
        );

        $this->assertDatabaseHas(
            'tbl_facility',
            [
                'facility_id' =>
                    $scenario['facility_id'],
                'facility_status' =>
                    'Available',
            ],
        );
    }

    /**
     * @return array{
     *   booking_id:int,
     *   booking_details_id:int,
     *   facility_id:int
     * }
     */
    private function createCheckedInBooking(
        int $cashierId,
    ): array {
        $facilityId = $this->createFacility();
        $guestId = $this->createGuest();

        $bookingPayload = [
            'b_ref_no' => 'B'.strtoupper(
                substr(
                    md5(uniqid('', true)),
                    0,
                    12,
                ),
            ),
            'guest_id' => $guestId,
            'booking_date' =>
                now()->toDateString(),
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
            $bookingPayload[
                'total_guest_count'
            ] = 4;
        }

        $bookingId = DB::table('tbl_booking')
            ->insertGetId($bookingPayload);

        $detailId = DB::table(
            'tbl_booking_details',
        )->insertGetId([
            'booking_id' => $bookingId,
            'facility_id' => $facilityId,
            'rate_type' => 'Overnight',
            'check_in_date' =>
                now()->toDateString(),
            'check_out_date' =>
                now()
                    ->addDay()
                    ->toDateString(),
            'check_in_time' =>
                now()->format('H:i:s'),
            'status' => 'Checked-in',
            'discount_id' => null,
            'user_id' => $cashierId,
        ]);

        return [
            'booking_id' => $bookingId,
            'booking_details_id' =>
                $detailId,
            'facility_id' => $facilityId,
        ];
    }

    private function createAmenity(
        string $name,
        float $price,
        string $type = 'Rentable',
    ): int {
        $amenityNameId = DB::table(
            'tbl_amenity_name',
        )->insertGetId([
            'amenity_name' =>
                $name.' '.uniqid(),
        ]);

        return DB::table('tbl_amenity')
            ->insertGetId([
                'amenity_name_id' =>
                    $amenityNameId,
                'amenity_description' =>
                    'Operational checkout test amenity',
                'amenity_type' => $type,
                'amenity_price' => $price,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createFacilityAmenity(
        int $facilityId,
        int $amenityId,
        int $quantity,
    ): int {
        return DB::table(
            'tbl_facility_amenities',
        )->insertGetId([
            'facility_id' => $facilityId,
            'amenity_id' => $amenityId,
            'amenity_quantity' => $quantity,
        ]);
    }

    private function createFine(
        int $amenityId,
        float $charge,
    ): int {
        $damageTypeId = DB::table(
            'tbl_damage_type',
        )->insertGetId([
            'damage_type' =>
                'Damaged '.uniqid(),
        ]);

        return DB::table('tbl_fine')
            ->insertGetId([
                'fine_type' => 'Amenity Fine',
                'amenity_id' => $amenityId,
                'damage_type_id' =>
                    $damageTypeId,
                'situational_fine' =>
                    'Damaged item',
                'situational_fine_description' =>
                    'Item was damaged by the guest.',
                'fine_charge' => $charge,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createPaymentMode(
        string $name,
    ): int {
        $existing = DB::table(
            'tbl_mode_of_payment',
        )
            ->where(
                'mode_of_payment',
                $name,
            )
            ->value(
                'mode_of_payment_id',
            );

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
        $facilityTypeId = DB::table(
            'tbl_facility_type',
        )
            ->where(
                'facility_type',
                'Room',
            )
            ->value(
                'facility_type_id',
            );

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
                'facility_status' =>
                    'Occupied',
                'capacity' => '10',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createGuest(): int
    {
        return DB::table('tbl_guest')
            ->insertGetId([
                'first_name' =>
                    'Operational',
                'middle_name' => null,
                'last_name' =>
                    'Checkout Guest',
                'contact_no' =>
                    '09123456789',
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
            ->where(
                'role_name',
                $roleName,
            )
            ->value('role_id');

        if ($roleId === null) {
            $roleId = DB::table(
                'tbl_role',
            )->insertGetId([
                'role_name' => $roleName,
            ]);
        }

        return DB::table('tbl_user')
            ->insertGetId([
                'first_name' => 'Test',
                'middle_name' => null,
                'last_name' =>
                    str_replace(
                        ' ',
                        '',
                        $roleName,
                    ),
                'username' => $username,
                'password' =>
                    Hash::make('password'),
                'email' =>
                    $username
                    .'@example.test',
                'contact_no' =>
                    '09999999999',
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
                'province' =>
                    'Sultan Kudarat',
                'city' =>
                    'Tacurong City',
                'barangay' =>
                    'Test Barangay',
            ]);
    }
}

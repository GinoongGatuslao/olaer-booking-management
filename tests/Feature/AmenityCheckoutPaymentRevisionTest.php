<?php

namespace Tests\Feature;

use App\Services\AmenityRequestWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class AmenityCheckoutPaymentRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_request_immediately_becomes_pending_and_adds_to_checkout_balance(): void
    {
        $facilityId = $this->createFacility();
        $bookingId = $this->createBooking('Checked-in', 0.00, 1000.00);
        $this->createBookingDetail($bookingId, $facilityId, 'Checked-in');
        $cashierId = $this->createUser('Cashier');
        $amenityId = $this->createAmenity(150.00);

        $request = app(AmenityRequestWorkflowService::class)
            ->createBillableRequest([
                'booking_id' => $bookingId,
                'facility_id' => $facilityId,
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
            $request->amenity_request_status,
        );

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $bookingId,
            'amount_due' => 300.00,
            'total_price' => 1300.00,
        ]);
    }

    public function test_maintenance_can_accept_pending_request_even_when_booking_has_unpaid_amenity_balance(): void
    {
        $bookingId = $this->createBooking('Checked-in', 250.00, 1250.00);
        $maintenanceId = $this->createUser('Maintenance Staff');
        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Pending',
            totalPrice: 250.00,
        );
        $this->createAmenityRequestDetail($requestId);

        $request = app(AmenityRequestWorkflowService::class)
            ->acceptRequest($requestId, $maintenanceId);

        $this->assertSame(
            'Delivering',
            $request->amenity_request_status,
        );

        $this->assertSame(
            $maintenanceId,
            (int) $request->assigned_to_user_id,
        );
    }

    public function test_maintenance_can_mark_delivered_even_when_booking_balance_is_unpaid(): void
    {
        $bookingId = $this->createBooking('Checked-in', 250.00, 1250.00);
        $maintenanceId = $this->createUser('Maintenance Staff');

        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Delivering',
            totalPrice: 250.00,
            assignedToUserId: $maintenanceId,
        );

        $this->createAmenityRequestDetail($requestId);

        $request = app(AmenityRequestWorkflowService::class)
            ->markDelivered($requestId, $maintenanceId);

        $this->assertSame(
            'Delivered',
            $request->amenity_request_status,
        );

        $this->assertNotNull($request->delivered_at);
    }

    public function test_pending_request_can_be_modified_before_maintenance_accepts_it(): void
    {
        $facilityId = $this->createFacility();
        $bookingId = $this->createBooking('Checked-in', 200.00, 1200.00);
        $this->createBookingDetail($bookingId, $facilityId, 'Checked-in');

        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Pending',
            totalPrice: 200.00,
        );

        $oldAmenityId = $this->createAmenity(200.00);
        $this->createAmenityRequestDetail(
            $requestId,
            $facilityId,
            $oldAmenityId,
            1,
            200.00,
        );

        $newAmenityId = $this->createAmenity(150.00);

        $request = app(AmenityRequestWorkflowService::class)
            ->updateBillableRequest(
                $requestId,
                $facilityId,
                [
                    [
                        'amenity_id' => $newAmenityId,
                        'quantity' => 1,
                    ],
                ],
            );

        $this->assertSame('150.00', (string) $request->total_price);

        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $bookingId,
            'amount_due' => 150.00,
            'total_price' => 1150.00,
        ]);
    }

    public function test_accepted_request_cannot_be_modified_or_cancelled_by_cashier(): void
    {
        $bookingId = $this->createBooking('Checked-in', 100.00, 1100.00);
        $maintenanceId = $this->createUser('Maintenance Staff');

        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Delivering',
            totalPrice: 100.00,
            assignedToUserId: $maintenanceId,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Only pending, undelivered amenity requests can be cancelled.',
        );

        app(AmenityRequestWorkflowService::class)
            ->cancelUnpaidRequest($requestId);
    }

    public function test_legacy_awaiting_payment_requests_are_released_to_pending_without_payment_check(): void
    {
        $bookingId = $this->createBooking('Checked-in', 500.00, 1500.00);

        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Awaiting Payment',
            totalPrice: 500.00,
        );

        $released = app(AmenityRequestWorkflowService::class)
            ->releasePaidRequestsForBooking($bookingId);

        $this->assertSame(1, $released);

        $this->assertDatabaseHas('tbl_amenity_request', [
            'amenity_request_id' => $requestId,
            'amenity_request_status' => 'Pending',
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

    private function createBooking(
        string $status,
        float $amountDue,
        float $totalPrice,
    ): int {
        $guestId = $this->createGuest();

        $payload = [
            'b_ref_no' => 'B'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_price' => $totalPrice,
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

    private function createAmenity(float $price): int
    {
        $nameId = DB::table('tbl_amenity_name')
            ->insertGetId([
                'amenity_name' => 'Amenity '.uniqid(),
            ]);

        return DB::table('tbl_amenity')
            ->insertGetId([
                'amenity_name_id' => $nameId,
                'amenity_description' => 'Guest rentable amenity',
                'amenity_type' => 'Rentable',
                'amenity_price' => $price,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createAmenityRequest(
        int $bookingId,
        string $status,
        float $totalPrice,
        ?int $assignedToUserId = null,
    ): int {
        return DB::table('tbl_amenity_request')
            ->insertGetId([
                'booking_id' => $bookingId,
                'amenity_request_status' => $status,
                'total_price' => $totalPrice,
                'date_created' => now()->toDateString(),
                'user_id' => null,
                'assigned_to_user_id' => $assignedToUserId,
                'delivered_at' => $status === 'Delivered' ? now() : null,
                'cancelled_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createAmenityRequestDetail(
        int $requestId,
        ?int $facilityId = null,
        ?int $amenityId = null,
        int $quantity = 1,
        float $unitPrice = 100.00,
    ): void {
        $facilityId ??= $this->createFacility();
        $amenityId ??= $this->createAmenity($unitPrice);

        DB::table('tbl_amenity_request_details')
            ->insert([
                'amenity_request_id' => $requestId,
                'facility_id' => $facilityId,
                'amenity_id' => $amenityId,
                'amenity_quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($unitPrice * $quantity, 2),
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
                'first_name' => 'Amenity',
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

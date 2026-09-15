<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AmenityRequestWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class AmenityRequestStateHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_cashier_or_maintenance_can_create_billable_amenity_request(): void
    {
        $facilityId = $this->createFacility();
        $bookingId = $this->createBooking('Checked-in', 0.00);
        $this->createBookingDetail($bookingId, $facilityId, 'Checked-in');
        $amenityId = $this->createAmenity(100.00);
        $managerId = $this->createUser('Manager');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Only Cashier or Maintenance Staff users can create amenity requests.',
        );

        app(AmenityRequestWorkflowService::class)
            ->createBillableRequest([
                'booking_id' => $bookingId,
                'facility_id' => $facilityId,
                'user_id' => $managerId,
                'items' => [
                    [
                        'amenity_id' => $amenityId,
                        'quantity' => 1,
                    ],
                ],
            ]);
    }

    public function test_paid_requests_are_not_released_after_booking_is_checked_out(): void
    {
        $bookingId = $this->createBooking('Checked-out', 0.00);
        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Awaiting Payment',
            totalPrice: 100.00,
        );

        $released = app(AmenityRequestWorkflowService::class)
            ->releasePaidRequestsForBooking($bookingId);

        $this->assertSame(0, $released);

        $this->assertDatabaseHas('tbl_amenity_request', [
            'amenity_request_id' => $requestId,
            'amenity_request_status' => 'Awaiting Payment',
        ]);
    }

    public function test_paid_requests_are_released_only_for_checked_in_zero_balance_booking(): void
    {
        $bookingId = $this->createBooking('Checked-in', 0.00);
        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Awaiting Payment',
            totalPrice: 100.00,
        );

        $released = app(AmenityRequestWorkflowService::class)
            ->releasePaidRequestsForBooking($bookingId);

        $this->assertSame(1, $released);

        $this->assertDatabaseHas('tbl_amenity_request', [
            'amenity_request_id' => $requestId,
            'amenity_request_status' => 'Pending',
        ]);
    }

    public function test_maintenance_cannot_accept_pending_request_after_checkout(): void
    {
        $bookingId = $this->createBooking('Checked-out', 0.00);
        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Pending',
            totalPrice: 100.00,
        );
        $this->createAmenityRequestDetail($requestId);
        $maintenanceId = $this->createUser('Maintenance Staff');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Amenity delivery is only allowed for checked-in bookings.',
        );

        app(AmenityRequestWorkflowService::class)
            ->acceptRequest($requestId, $maintenanceId);
    }

    public function test_assigned_maintenance_mark_delivered_is_idempotent(): void
    {
        $bookingId = $this->createBooking('Checked-in', 0.00);
        $maintenanceId = $this->createUser('Maintenance Staff');

        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Delivering',
            totalPrice: 100.00,
            assignedToUserId: $maintenanceId,
        );

        $this->createAmenityRequestDetail($requestId);

        $service = app(AmenityRequestWorkflowService::class);

        $first = $service->markDelivered($requestId, $maintenanceId);
        $firstDeliveredAt = (string) $first->delivered_at;

        $second = $service->markDelivered($requestId, $maintenanceId);

        $this->assertSame('Delivered', $second->amenity_request_status);
        $this->assertSame(
            $firstDeliveredAt,
            (string) $second->delivered_at,
        );

        $this->assertDatabaseHas('tbl_amenity_request', [
            'amenity_request_id' => $requestId,
            'amenity_request_status' => 'Delivered',
            'assigned_to_user_id' => $maintenanceId,
        ]);
    }

    public function test_cancel_pending_request_blocks_inconsistent_balance(): void
    {
        $bookingId = $this->createBooking('Checked-in', 50.00);

        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Pending',
            totalPrice: 100.00,
        );
        $cashierId = $this->createUser('Cashier');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'booking balance is inconsistent',
        );

        app(AmenityRequestWorkflowService::class)
            ->cancelUnpaidRequest($requestId, $cashierId);
    }

    public function test_maintenance_create_route_renders_the_dedicated_component_without_cashier_actions(): void
    {
        $maintenance = User::query()->findOrFail(
            $this->createUser('Maintenance Staff'),
        );

        $routes = file_get_contents(base_path('routes/web.php'));
        $component = file_get_contents(resource_path(
            'views/livewire/maintenance/amenity-requests/index.blade.php',
        ));

        $this->assertIsString($routes);
        $this->assertIsString($component);
        $this->assertStringContainsString(
            "Volt::route('/maintenance/create-amenity-request', 'maintenance.amenity-requests.index')",
            $routes,
        );
        $this->assertStringNotContainsString(
            "Volt::route('/maintenance/create-amenity-request', 'cashier.amenity-requests.index')",
            $routes,
        );

        foreach (['saveEdit', 'cancelRequest', 'reassign', 'verifyPayment'] as $cashierAction) {
            $this->assertStringNotContainsString(
                $cashierAction,
                $component,
            );
        }

        $this->actingAs($maintenance)
            ->get(route('maintenance.create-amenity-request.index'))
            ->assertOk()
            ->assertSee('Amenity Delivery Queue')
            ->assertSee('Create Amenity Request')
            ->assertDontSee('Modify Pending Amenity Request')
            ->assertDontSee('Payment Verification');
    }

    public function test_maintenance_can_create_and_deliver_its_own_unpaid_amenity_request(): void
    {
        $facilityId = $this->createFacility();
        $bookingId = $this->createBooking('Checked-in', 500.00);
        $bookingDetailId = $this->createBookingDetail(
            $bookingId,
            $facilityId,
            'Checked-in',
        );
        $amenityId = $this->createAmenity(100.00);
        $maintenance = User::query()->findOrFail(
            $this->createUser('Maintenance Staff'),
        );
        $otherMaintenance = User::query()->findOrFail(
            $this->createUser('Maintenance Staff'),
        );

        Livewire::actingAs($maintenance)
            ->test('maintenance.amenity-requests.index')
            ->set('form.booking_id', (string) $bookingId)
            ->set(
                'form.booking_detail_id',
                (string) $bookingDetailId,
            )
            ->set('items', [[
                'amenity_id' => (string) $amenityId,
                'quantity' => 2,
            ]])
            ->call('createRequest')
            ->assertHasNoErrors();

        $requestId = (int) DB::table('tbl_amenity_request')
            ->where('booking_id', $bookingId)
            ->value('amenity_request_id');

        $this->assertDatabaseHas('tbl_amenity_request', [
            'amenity_request_id' => $requestId,
            'user_id' => $maintenance->user_id,
            'assigned_to_user_id' => $maintenance->user_id,
            'amenity_request_status' => 'Delivering',
        ]);
        $this->assertDatabaseHas('tbl_booking', [
            'booking_id' => $bookingId,
            'amount_due' => 700.00,
        ]);

        Livewire::actingAs($otherMaintenance)
            ->test('maintenance.amenity-requests.index')
            ->call('markDelivered', $requestId)
            ->assertHasErrors('request');

        $this->assertDatabaseHas('tbl_amenity_request', [
            'amenity_request_id' => $requestId,
            'amenity_request_status' => 'Delivering',
        ]);

        Livewire::actingAs($maintenance)
            ->test('maintenance.amenity-requests.index')
            ->call('markDelivered', $requestId)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tbl_amenity_request', [
            'amenity_request_id' => $requestId,
            'amenity_request_status' => 'Delivered',
            'assigned_to_user_id' => $maintenance->user_id,
        ]);
    }

    public function test_maintenance_creation_rejects_tampered_association_ids(): void
    {
        $facilityId = $this->createFacility();
        $otherFacilityId = $this->createFacility();
        $bookingId = $this->createBooking('Checked-in', 0.00);
        $otherBookingId = $this->createBooking('Checked-in', 0.00);
        $bookingDetailId = $this->createBookingDetail(
            $bookingId,
            $facilityId,
            'Checked-in',
        );
        $otherBookingDetailId = $this->createBookingDetail(
            $otherBookingId,
            $otherFacilityId,
            'Checked-in',
        );
        $amenityId = $this->createAmenity(100.00);
        $maintenanceId = $this->createUser('Maintenance Staff');
        $workflow = app(AmenityRequestWorkflowService::class);

        $valid = [
            'booking_id' => $bookingId,
            'booking_detail_id' => $bookingDetailId,
            'facility_id' => $facilityId,
            'user_id' => $maintenanceId,
            'items' => [[
                'amenity_id' => $amenityId,
                'quantity' => 1,
            ]],
        ];

        $tamperedPayloads = [
            'booking' => [
                ...$valid,
                'booking_id' => 999999,
            ],
            'booking detail' => [
                ...$valid,
                'booking_detail_id' => $otherBookingDetailId,
            ],
            'facility' => [
                ...$valid,
                'facility_id' => $otherFacilityId,
            ],
            'amenity' => [
                ...$valid,
                'items' => [[
                    'amenity_id' => 999999,
                    'quantity' => 1,
                ]],
            ],
        ];

        foreach ($tamperedPayloads as $name => $payload) {
            try {
                $workflow->createBillableRequest($payload);
                $this->fail("Tampered {$name} ID was accepted.");
            } catch (\Throwable) {
                $this->assertDatabaseCount('tbl_amenity_request', 0);
            }
        }
    }

    public function test_cashier_update_and_cancel_actions_reauthorize_the_actor(): void
    {
        $facilityId = $this->createFacility();
        $bookingId = $this->createBooking('Checked-in', 100.00);
        $this->createBookingDetail($bookingId, $facilityId, 'Checked-in');
        $amenityId = $this->createAmenity(100.00);
        $requestId = $this->createAmenityRequest(
            bookingId: $bookingId,
            status: 'Pending',
            totalPrice: 100.00,
        );
        $this->createAmenityRequestDetailFor(
            $requestId,
            $facilityId,
            $amenityId,
        );
        $maintenanceId = $this->createUser('Maintenance Staff');
        $workflow = app(AmenityRequestWorkflowService::class);

        foreach ([
            fn () => $workflow->updateBillableRequest(
                $requestId,
                $facilityId,
                [['amenity_id' => $amenityId, 'quantity' => 1]],
                $maintenanceId,
            ),
            fn () => $workflow->cancelUnpaidRequest(
                $requestId,
                $maintenanceId,
            ),
        ] as $cashierOnlyAction) {
            try {
                $cashierOnlyAction();
                $this->fail('Maintenance invoked a Cashier-only action.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'Only a Cashier',
                    $exception->getMessage(),
                );
            }
        }

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
    ): int {
        $guestId = $this->createGuest();

        $payload = [
            'b_ref_no' => 'B'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_price' => max(1000.00, $amountDue),
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
                'amenity_name' => 'Towel '.uniqid(),
            ]);

        return DB::table('tbl_amenity')
            ->insertGetId([
                'amenity_name_id' => $nameId,
                'amenity_description' => 'Rental towel',
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
        $payload = [
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
        ];

        return DB::table('tbl_amenity_request')
            ->insertGetId($payload);
    }

    private function createAmenityRequestDetail(int $requestId): void
    {
        $facilityId = $this->createFacility();
        $amenityId = $this->createAmenity(100.00);

        DB::table('tbl_amenity_request_details')
            ->insert([
                'amenity_request_id' => $requestId,
                'facility_id' => $facilityId,
                'amenity_id' => $amenityId,
                'amenity_quantity' => 1,
                'unit_price' => 100.00,
                'line_total' => 100.00,
            ]);
    }

    private function createAmenityRequestDetailFor(
        int $requestId,
        int $facilityId,
        int $amenityId,
    ): void {
        DB::table('tbl_amenity_request_details')
            ->insert([
                'amenity_request_id' => $requestId,
                'facility_id' => $facilityId,
                'amenity_id' => $amenityId,
                'amenity_quantity' => 1,
                'unit_price' => 100.00,
                'line_total' => 100.00,
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

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CheckOutInspectionRequestService;
use App\Services\OperationalAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationalAlertRoutingAndScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_gcash_alert_uses_an_existing_cashier_route(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'alert_gcash_cashier',
        );

        $scenario = $this->createBookingScenario(
            $cashier,
            bookingStatus: 'Pending Verification',
            detailStatus: 'Booked',
            amountDue: 1000.00,
        );

        $paymentId = $this->createPayment(
            $scenario['booking_id'],
            $cashier,
            'Pending',
            proofPath:
                'gcash-proofs/pending-alert.jpg',
        );

        $alert = collect(
            app(
                OperationalAlertService::class,
            )->cashierAlerts(),
        )->firstWhere(
            'type',
            'gcash_verification',
        );

        $this->assertNotNull($alert);

        $this->assertSame(
            'cashier.gcash-verifications.index',
            $alert['route_name'],
        );

        $this->assertSame(
            ['payment' => $paymentId],
            $alert['route_params'],
        );

        $this->assertTrue(
            Route::has(
                $alert['route_name'],
            ),
        );

        $upcomingAlert = collect(
            app(
                OperationalAlertService::class,
            )->cashierAlerts(),
        )->firstWhere(
            'type',
            'upcoming_booking',
        );

        $this->assertNotNull(
            $upcomingAlert,
        );

        $this->assertSame(
            'cashier.check-ins.index',
            $upcomingAlert['route_name'],
        );

        $this->assertSame(
            [
                'booking' =>
                    $scenario['booking_id'],
            ],
            $upcomingAlert['route_params'],
        );
    }

    public function test_pending_amenity_alert_appears_before_payment_and_uses_existing_route(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'alert_amenity_cashier',
        );

        $scenario = $this->createBookingScenario(
            $cashier,
            bookingStatus: 'Checked-in',
            detailStatus: 'Checked-in',
            amountDue: 250.00,
        );

        $requestId = $this->createAmenityRequest(
            $scenario,
            'Extra Mattress',
            250.00,
        );

        $alert = collect(
            app(
                OperationalAlertService::class,
            )->maintenanceAlerts(),
        )->firstWhere(
            'type',
            'pending_amenity_request',
        );

        $this->assertNotNull($alert);

        $this->assertSame(
            'maintenance.amenity-requests.index',
            $alert['route_name'],
        );

        $this->assertTrue(
            Route::has(
                $alert['route_name'],
            ),
        );

        $this->assertSame(
            ['q' => (string) $requestId],
            $alert['route_params'],
        );

        $this->assertStringContainsString(
            'payment is settled through the booking bill',
            $alert['message'],
        );

        $paymentAlert = collect(
            app(
                OperationalAlertService::class,
            )->cashierAlerts(),
        )->firstWhere(
            'type',
            'unpaid_checkout_balance',
        );

        $this->assertNotNull(
            $paymentAlert,
        );

        $this->assertSame(
            'cashier.payments.index',
            $paymentAlert['route_name'],
        );

        $this->assertSame(
            [
                'booking' =>
                    $scenario['booking_id'],
            ],
            $paymentAlert['route_params'],
        );
    }

    public function test_checked_in_facility_without_cashier_request_does_not_alert_maintenance(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'alert_no_request_cashier',
        );

        $this->createBookingScenario(
            $cashier,
            bookingStatus: 'Checked-in',
            detailStatus: 'Checked-in',
            amountDue: 0.00,
        );

        $inspectionAlerts = collect(
            app(
                OperationalAlertService::class,
            )->maintenanceAlerts(),
        )->where(
            'type',
            'inspection_request',
        );

        $this->assertCount(
            0,
            $inspectionAlerts,
        );
    }

    public function test_cashier_created_inspection_request_alerts_maintenance_and_in_progress_remains_visible(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'alert_request_cashier',
        );

        $maintenance = $this->createUser(
            'Maintenance Staff',
            'alert_request_maintenance',
        );

        $scenario = $this->createBookingScenario(
            $cashier,
            bookingStatus: 'Checked-in',
            detailStatus: 'Checked-in',
            amountDue: 0.00,
        );

        $request = app(
            CheckOutInspectionRequestService::class,
        )->requestInspection(
            $scenario['booking_details_id'],
            $cashier->user_id,
            'Guest is ready for checkout.',
        );

        $pendingAlert = collect(
            app(
                OperationalAlertService::class,
            )->maintenanceAlerts(),
        )->firstWhere(
            'type',
            'inspection_request',
        );

        $this->assertNotNull(
            $pendingAlert,
        );

        $this->assertSame(
            'Pending checkout inspection',
            $pendingAlert['title'],
        );

        $this->assertSame(
            'maintenance.facility-inspections.index',
            $pendingAlert['route_name'],
        );

        $this->assertTrue(
            Route::has(
                $pendingAlert['route_name'],
            ),
        );

        $this->assertSame(
            [
                'request' => $request
                    ->facility_inspection_request_id,
            ],
            $pendingAlert['route_params'],
        );

        app(
            CheckOutInspectionRequestService::class,
        )->acceptRequest(
            $request
                ->facility_inspection_request_id,
            $maintenance->user_id,
        );

        $inProgressAlert = collect(
            app(
                OperationalAlertService::class,
            )->maintenanceAlerts(),
        )->firstWhere(
            'type',
            'inspection_request',
        );

        $this->assertNotNull(
            $inProgressAlert,
        );

        $this->assertSame(
            'Inspection in progress',
            $inProgressAlert['title'],
        );

        $this->assertSame(
            'Continue inspection',
            $inProgressAlert[
                'action_label'
            ],
        );

        $this->assertSame(
            [
                'request' => $request
                    ->facility_inspection_request_id,
            ],
            $inProgressAlert['route_params'],
        );
    }

    public function test_cottage_ending_alert_opens_the_exact_booking_workspace(): void
    {
        Carbon::setTestNow(
            '2026-07-24 17:00:00',
        );

        try {
            $cashier = $this->createUser(
                'Cashier',
                'alert_cottage_cashier',
            );

            $scenario =
                $this->createBookingScenario(
                    $cashier,
                    bookingStatus: 'Checked-in',
                    detailStatus: 'Checked-in',
                    amountDue: 0.00,
                );

            $cottageTypeId = DB::table(
                'tbl_facility_type',
            )
                ->where(
                    'facility_type',
                    'Cottage',
                )
                ->value(
                    'facility_type_id',
                );

            if ($cottageTypeId === null) {
                $cottageTypeId = DB::table(
                    'tbl_facility_type',
                )->insertGetId([
                    'facility_type' =>
                        'Cottage',
                ]);
            }

            DB::table('tbl_facility')
                ->where(
                    'facility_id',
                    $scenario['facility_id'],
                )
                ->update([
                    'facility_type_id' =>
                        $cottageTypeId,
                ]);

            DB::table(
                'tbl_booking_details',
            )
                ->where(
                    'booking_details_id',
                    $scenario[
                        'booking_details_id'
                    ],
                )
                ->update([
                    'rate_type' => 'Day Use',
                    'check_out_date' =>
                        now()->toDateString(),
                ]);

            $alert = collect(
                app(
                    OperationalAlertService::class,
                )->cashierAlerts(),
            )->firstWhere(
                'type',
                'rental_period_ending',
            );

            $this->assertNotNull($alert);

            $this->assertSame(
                'cashier.bookings.show',
                $alert['route_name'],
            );

            $this->assertSame(
                [
                    'booking' =>
                        $scenario['booking_id'],
                ],
                $alert['route_params'],
            );

            $this->assertSame(
                'Open booking',
                $alert['action_label'],
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_completed_inspection_request_is_removed_from_maintenance_alerts(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'alert_completed_cashier',
        );

        $scenario = $this->createBookingScenario(
            $cashier,
            bookingStatus: 'Checked-in',
            detailStatus: 'Checked-in',
            amountDue: 0.00,
        );

        $request = app(
            CheckOutInspectionRequestService::class,
        )->requestInspection(
            $scenario['booking_details_id'],
            $cashier->user_id,
            'Checkout inspection requested.',
        );

        DB::table(
            'tbl_facility_inspection_request',
        )
            ->where(
                'facility_inspection_request_id',
                $request
                    ->facility_inspection_request_id,
            )
            ->update([
                'status' => 'Completed',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        $alert = collect(
            app(
                OperationalAlertService::class,
            )->maintenanceAlerts(),
        )->firstWhere(
            'type',
            'inspection_request',
        );

        $this->assertNull($alert);
    }

    public function test_live_alert_pages_poll_only_while_visible_and_documentation_uses_current_amenity_rule(): void
    {
        $expectedPolling = [
            'resources/views/livewire/cashier/action-center/index.blade.php' =>
                'wire:poll.10s.visible="refreshActionCenter"',
            'resources/views/livewire/cashier/notifications/index.blade.php' =>
                'wire:poll.15s.visible="refreshAlerts"',
            'resources/views/livewire/maintenance/action-center/index.blade.php' =>
                'wire:poll.10s.visible',
            'resources/views/livewire/maintenance/notifications/index.blade.php' =>
                'wire:poll.15s.visible="refreshAlerts"',
        ];

        foreach (
            $expectedPolling
            as $relativePath => $directive
        ) {
            $content = file_get_contents(
                base_path($relativePath),
            );

            $this->assertIsString($content);

            $this->assertStringContainsString(
                $directive,
                $content,
            );
        }

        $parameterizedAlertViews = [
            'resources/views/livewire/cashier/action-center/index.blade.php',
            'resources/views/livewire/cashier/notifications/index.blade.php',
            'resources/views/livewire/maintenance/notifications/index.blade.php',
        ];

        foreach (
            $parameterizedAlertViews
            as $relativePath
        ) {
            $content = file_get_contents(
                base_path($relativePath),
            );

            $this->assertIsString($content);

            $this->assertStringContainsString(
                "route(\$routeName, \$alert['route_params'] ?? [])",
                $content,
            );
        }

        $maintenanceNotifications =
            file_get_contents(
                resource_path(
                    'views/livewire/maintenance/notifications/index.blade.php',
                ),
            );

        $this->assertIsString(
            $maintenanceNotifications,
        );

        $this->assertStringContainsString(
            "where('type', 'inspection_request')",
            $maintenanceNotifications,
        );

        $this->assertStringContainsString(
            'may be delivered before payment',
            $maintenanceNotifications,
        );

        $readme = file_get_contents(
            base_path('README.md'),
        );

        $this->assertIsString($readme);

        $this->assertStringNotContainsString(
            'Awaiting Payment',
            $readme,
        );

        $this->assertStringContainsString(
            'Delivery does not require advance payment.',
            $readme,
        );
    }

    /**
     * @return array{
     *   booking_id:int,
     *   booking_details_id:int,
     *   facility_id:int
     * }
     */
    private function createBookingScenario(
        User $cashier,
        string $bookingStatus,
        string $detailStatus,
        float $amountDue,
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
            'total_price' => max(
                1000.00,
                $amountDue,
            ),
            'amount_due' => $amountDue,
            'user_id' => $cashier->user_id,
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
            'status' => $detailStatus,
            'discount_id' => null,
            'user_id' => $cashier->user_id,
        ]);

        return [
            'booking_id' => $bookingId,
            'booking_details_id' =>
                $detailId,
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
    private function createAmenityRequest(
        array $scenario,
        string $name,
        float $price,
    ): int {
        $amenityNameId = DB::table(
            'tbl_amenity_name',
        )->insertGetId([
            'amenity_name' => $name,
        ]);

        $amenityId = DB::table('tbl_amenity')
            ->insertGetId([
                'amenity_name_id' =>
                    $amenityNameId,
                'amenity_description' =>
                    'Operational alert test amenity.',
                'amenity_type' => 'Rentable',
                'amenity_price' => $price,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $requestPayload = [
            'booking_id' =>
                $scenario['booking_id'],
            'amenity_request_status' =>
                'Pending',
            'total_price' => $price,
            'date_created' =>
                now()->toDateString(),
            'user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (
            Schema::hasColumn(
                'tbl_amenity_request',
                'assigned_to_user_id',
            )
        ) {
            $requestPayload[
                'assigned_to_user_id'
            ] = null;
        }

        if (
            Schema::hasColumn(
                'tbl_amenity_request',
                'accepted_at',
            )
        ) {
            $requestPayload['accepted_at'] =
                null;
        }

        if (
            Schema::hasColumn(
                'tbl_amenity_request',
                'delivered_at',
            )
        ) {
            $requestPayload['delivered_at'] =
                null;
        }

        $requestId = DB::table(
            'tbl_amenity_request',
        )->insertGetId($requestPayload);

        $detailPayload = [
            'amenity_request_id' =>
                $requestId,
            'facility_id' =>
                $scenario['facility_id'],
            'amenity_id' => $amenityId,
            'amenity_quantity' => 1,
        ];

        if (
            Schema::hasColumn(
                'tbl_amenity_request_details',
                'unit_price',
            )
        ) {
            $detailPayload['unit_price'] =
                $price;
        }

        if (
            Schema::hasColumn(
                'tbl_amenity_request_details',
                'line_total',
            )
        ) {
            $detailPayload['line_total'] =
                $price;
        }

        DB::table(
            'tbl_amenity_request_details',
        )->insert($detailPayload);

        return $requestId;
    }

    private function createPayment(
        int $bookingId,
        User $cashier,
        string $status,
        ?string $proofPath,
    ): int {
        $payload = [
            'p_ref_no' => 'P'.strtoupper(
                substr(
                    md5(uniqid('', true)),
                    0,
                    12,
                ),
            ),
            'booking_id' => $bookingId,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'mode_of_payment_id' =>
                $this->createPaymentMode(
                    'GCash',
                ),
            'reference_number' =>
                'GCASH-'.uniqid(),
            'proof_of_payment_path' =>
                $proofPath,
            'amount_paid' => 1000.00,
            'date_paid' =>
                now()->toDateString(),
            'user_id' => $cashier->user_id,
            'payment_status' => $status,
            'verified_by_user_id' => null,
            'verified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (
            Schema::hasColumn(
                'tbl_payment',
                'rejection_reason',
            )
        ) {
            $payload['rejection_reason'] =
                null;
        }

        return DB::table('tbl_payment')
            ->insertGetId($payload);
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
                    'Alert Room '.uniqid(),
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
                'first_name' => 'Alert',
                'middle_name' => null,
                'last_name' => 'Guest',
                'contact_no' =>
                    '09123456789',
                'address_id' =>
                    $this->createAddress(),
                'email' =>
                    uniqid(
                        'alert_',
                        true,
                    )
                    .'@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function createUser(
        string $roleName,
        string $username,
    ): User {
        return User::query()->create([
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
                $username.'@example.test',
            'contact_no' => '09999999999',
            'status' => 'Active',
            'address_id' =>
                $this->createAddress(),
            'role_id' =>
                $this->createRole(
                    $roleName,
                ),
        ]);
    }

    private function createRole(
        string $roleName,
    ): int {
        $existing = DB::table('tbl_role')
            ->where(
                'role_name',
                $roleName,
            )
            ->value('role_id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return DB::table('tbl_role')
            ->insertGetId([
                'role_name' => $roleName,
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

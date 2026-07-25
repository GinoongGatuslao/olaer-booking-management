<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BillingStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BillingAndPrintIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_billing_summary_counts_each_booking_once_and_uses_booking_wide_balance(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'billing_summary_cashier',
        );

        $scenario = $this->createBookingScenario(
            $cashier,
            totalPrice: 1300.00,
            amountDue: 300.00,
        );

        $this->createAmenityRequest(
            $scenario,
            'Extra Mattress',
            'Pending',
            200.00,
        );

        $this->createGuestFine(
            $scenario,
            $cashier,
            'Room Towel',
            100.00,
        );

        $billing = app(
            BillingStatementService::class,
        )->paginatedRecords(
            [],
            25,
        );

        $this->assertSame(
            3,
            $billing['count'],
        );

        $this->assertSame(
            1300.00,
            $billing['total_amount'],
        );

        $this->assertSame(
            300.00,
            $billing['total_due'],
        );

        $this->assertSame(
            0,
            $billing['paid_count'],
        );

        $this->assertSame(
            1,
            $billing['unpaid_count'],
        );

        $rows = collect(
            $billing['rows']->items(),
        );

        $amenityRow = $rows->first(
            fn (array $row): bool =>
                $row['transaction_type']
                === 'Amenity Request',
        );

        $fineRow = $rows->first(
            fn (array $row): bool =>
                $row['transaction_type']
                === 'Fine',
        );

        $this->assertNotNull($amenityRow);
        $this->assertNotNull($fineRow);

        $this->assertSame(
            300.00,
            (float) $amenityRow['amount_due'],
        );

        $this->assertSame(
            'Unpaid',
            $amenityRow['payment_status'],
        );

        $this->assertSame(
            300.00,
            (float) $fineRow['amount_due'],
        );

        $this->assertSame(
            'Unpaid',
            $fineRow['payment_status'],
        );
    }

    public function test_pending_payment_cannot_be_printed_as_receipt(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'pending_receipt_cashier',
        );

        $scenario = $this->createBookingScenario(
            $cashier,
            totalPrice: 1000.00,
            amountDue: 1000.00,
        );

        $paymentId = $this->createPayment(
            $scenario['booking_id'],
            $cashier,
            'Pending',
            1000.00,
            'P-PENDING-PRINT',
        );

        $this->actingAs($cashier)
            ->get(
                route(
                    'print.payment',
                    $paymentId,
                ),
            )
            ->assertStatus(409);
    }

    public function test_verified_payment_can_be_printed_as_receipt(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'verified_receipt_cashier',
        );

        $scenario = $this->createBookingScenario(
            $cashier,
            totalPrice: 1000.00,
            amountDue: 0.00,
        );

        $paymentId = $this->createPayment(
            $scenario['booking_id'],
            $cashier,
            'verified',
            1000.00,
            'P-VERIFIED-PRINT',
        );

        $this->actingAs($cashier)
            ->get(
                route(
                    'print.payment',
                    $paymentId,
                ),
            )
            ->assertOk()
            ->assertSee('Payment Receipt')
            ->assertSee('P-VERIFIED-PRINT')
            ->assertSee('1,000.00');
    }

    public function test_printed_billing_statement_uses_canonical_statement_data(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'billing_print_cashier',
        );

        $scenario = $this->createBookingScenario(
            $cashier,
            totalPrice: 1300.00,
            amountDue: 300.00,
        );

        $this->createAmenityRequest(
            $scenario,
            'Delivered Mattress',
            'Delivered',
            200.00,
        );

        $this->createAmenityRequest(
            $scenario,
            'Cancelled Pillow',
            'Cancelled',
            50.00,
        );

        $this->createGuestFine(
            $scenario,
            $cashier,
            'Room Towel',
            100.00,
        );

        $this->createPayment(
            $scenario['booking_id'],
            $cashier,
            'verified',
            1000.00,
            'P-BILLING-PRINT',
        );

        $this->actingAs($cashier)
            ->get(
                route(
                    'print.billing',
                    $scenario['booking_id'],
                ),
            )
            ->assertOk()
            ->assertSee('Billing Statement')
            ->assertSee('Delivered Mattress')
            ->assertDontSee('Cancelled Pillow')
            ->assertSee('Room Towel')
            ->assertSee('P-BILLING-PRINT')
            ->assertSee('Verified Paid')
            ->assertSee('1,000.00')
            ->assertSee('300.00');
    }

    public function test_maintenance_staff_cannot_access_cashier_print_documents(): void
    {
        $cashier = $this->createUser(
            'Cashier',
            'print_owner_cashier',
        );

        $maintenance = $this->createUser(
            'Maintenance Staff',
            'print_maintenance_user',
        );

        $scenario = $this->createBookingScenario(
            $cashier,
            totalPrice: 1000.00,
            amountDue: 0.00,
        );

        $paymentId = $this->createPayment(
            $scenario['booking_id'],
            $cashier,
            'Verified',
            1000.00,
            'P-ROLE-PRINT',
        );

        $this->actingAs($maintenance)
            ->get(
                route(
                    'print.payment',
                    $paymentId,
                ),
            )
            ->assertForbidden();

        $this->actingAs($maintenance)
            ->get(
                route(
                    'print.billing',
                    $scenario['booking_id'],
                ),
            )
            ->assertForbidden();
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
        float $totalPrice,
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
            'booking_date' => '2026-07-20',
            'no_of_extra_guests' => 0,
            'total_price' => $totalPrice,
            'amount_due' => $amountDue,
            'user_id' => $cashier->user_id,
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

        $detailPayload = [
            'booking_id' => $bookingId,
            'facility_id' => $facilityId,
            'rate_type' => 'Overnight',
            'check_in_date' => '2026-07-20',
            'check_out_date' => '2026-07-21',
            'check_in_time' => '14:00:00',
            'status' => 'Checked-in',
            'discount_id' => null,
            'user_id' => $cashier->user_id,
        ];

        if (
            Schema::hasColumn(
                'tbl_booking_details',
                'base_price',
            )
        ) {
            $detailPayload['base_price'] = 1000.00;
            $detailPayload['discount_amount'] = 0.00;
            $detailPayload['extra_guest_fee'] = 0.00;
            $detailPayload['line_total'] = 1000.00;
        }

        $detailId = DB::table(
            'tbl_booking_details',
        )->insertGetId($detailPayload);

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
    private function createAmenityRequest(
        array $scenario,
        string $name,
        string $status,
        float $totalPrice,
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
                    'Billing and print integrity test amenity.',
                'amenity_type' => 'Rentable',
                'amenity_price' => $totalPrice,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $requestPayload = [
            'booking_id' => $scenario['booking_id'],
            'amenity_request_status' => $status,
            'total_price' => $totalPrice,
            'date_created' => '2026-07-20',
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
            $requestPayload['assigned_to_user_id'] = null;
        }

        if (
            Schema::hasColumn(
                'tbl_amenity_request',
                'accepted_at',
            )
        ) {
            $requestPayload['accepted_at'] = null;
        }

        if (
            Schema::hasColumn(
                'tbl_amenity_request',
                'delivered_at',
            )
        ) {
            $requestPayload['delivered_at'] =
                $status === 'Delivered'
                    ? now()
                    : null;
        }

        $requestId = DB::table(
            'tbl_amenity_request',
        )->insertGetId($requestPayload);

        $detailPayload = [
            'amenity_request_id' => $requestId,
            'facility_id' => $scenario['facility_id'],
            'amenity_id' => $amenityId,
            'amenity_quantity' => 1,
        ];

        if (
            Schema::hasColumn(
                'tbl_amenity_request_details',
                'unit_price',
            )
        ) {
            $detailPayload['unit_price'] = $totalPrice;
        }

        if (
            Schema::hasColumn(
                'tbl_amenity_request_details',
                'line_total',
            )
        ) {
            $detailPayload['line_total'] = $totalPrice;
        }

        DB::table(
            'tbl_amenity_request_details',
        )->insert($detailPayload);

        return $requestId;
    }

    /**
     * @param array{
     *   booking_id:int,
     *   booking_details_id:int,
     *   facility_id:int
     * } $scenario
     */
    private function createGuestFine(
        array $scenario,
        User $cashier,
        string $amenityName,
        float $charge,
    ): int {
        $amenityNameId = DB::table(
            'tbl_amenity_name',
        )->insertGetId([
            'amenity_name' => $amenityName,
        ]);

        $amenityId = DB::table('tbl_amenity')
            ->insertGetId([
                'amenity_name_id' =>
                    $amenityNameId,
                'amenity_description' =>
                    'Fine test amenity.',
                'amenity_type' => 'Rentable',
                'amenity_price' => $charge,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $damageTypeId = DB::table(
            'tbl_damage_type',
        )->insertGetId([
            'damage_type' => 'Damaged',
        ]);

        $fineId = DB::table('tbl_fine')
            ->insertGetId([
                'fine_type' => 'Amenity',
                'amenity_id' => $amenityId,
                'damage_type_id' => $damageTypeId,
                'situational_fine' => 'Damaged item',
                'situational_fine_description' =>
                    'Item damaged during the stay.',
                'fine_charge' => $charge,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $guestFinePayload = [
            'booking_id' => $scenario['booking_id'],
            'fine_id' => $fineId,
            'quantity' => 1,
            'facility_id' => $scenario['facility_id'],
            'total_charge' => $charge,
            'date_checked' => '2026-07-20',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (
            Schema::hasColumn(
                'tbl_guest_fine',
                'booking_details_id',
            )
        ) {
            $guestFinePayload['booking_details_id'] =
                $scenario['booking_details_id'];
        }

        if (
            Schema::hasColumn(
                'tbl_guest_fine',
                'item_source',
            )
        ) {
            $guestFinePayload['item_source'] =
                'facility_amenity';
        }

        if (
            Schema::hasColumn(
                'tbl_guest_fine',
                'source_id',
            )
        ) {
            $guestFinePayload['source_id'] = null;
        }

        if (
            Schema::hasColumn(
                'tbl_guest_fine',
                'reported_by_user_id',
            )
        ) {
            $guestFinePayload['reported_by_user_id'] =
                $cashier->user_id;
        }

        return DB::table('tbl_guest_fine')
            ->insertGetId($guestFinePayload);
    }

    private function createPayment(
        int $bookingId,
        User $cashier,
        string $status,
        float $amount,
        string $reference,
    ): int {
        $payload = [
            'p_ref_no' => $reference,
            'booking_id' => $bookingId,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'mode_of_payment_id' =>
                $this->createPaymentMode('GCash'),
            'reference_number' =>
                'GCASH-'.$reference,
            'proof_of_payment_path' => null,
            'amount_paid' => $amount,
            'date_paid' => '2026-07-20',
            'user_id' => $cashier->user_id,
            'payment_status' => $status,
            'verified_by_user_id' =>
                strtolower($status) === 'verified'
                    ? $cashier->user_id
                    : null,
            'verified_at' =>
                strtolower($status) === 'verified'
                    ? now()
                    : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (
            Schema::hasColumn(
                'tbl_payment',
                'rejection_reason',
            )
        ) {
            $payload['rejection_reason'] = null;
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
            ->value('mode_of_payment_id');

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
                    'Billing Room '.uniqid(),
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
                'first_name' => 'Billing',
                'middle_name' => null,
                'last_name' => 'Guest',
                'contact_no' => '09123456789',
                'address_id' =>
                    $this->createAddress(),
                'email' =>
                    uniqid('billing_', true)
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
                str_replace(' ', '', $roleName),
            'username' => $username,
            'password' => Hash::make('password'),
            'email' => $username.'@example.test',
            'contact_no' => '09999999999',
            'status' => 'Active',
            'address_id' =>
                $this->createAddress(),
            'role_id' =>
                $this->createRole($roleName),
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
                'province' => 'Sultan Kudarat',
                'city' => 'Tacurong City',
                'barangay' => 'Test Barangay',
            ]);
    }
}

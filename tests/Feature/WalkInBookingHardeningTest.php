<?php

namespace Tests\Feature;

use App\FacilityProductCode;
use App\FacilityRateCode;
use App\Models\Amenity;
use App\Models\FacilityProduct;
use App\Models\ModeOfPayment;
use App\Models\User;
use App\Services\FacilityAssignmentService;
use App\Services\FacilityRequirementService;
use App\Services\TransactionLedgerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalkInBookingHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_walk_in_requires_full_core_payment_but_can_leave_amenity_balance(): void
    {
        $this->seed(DatabaseSeeder::class);

        $cashier = User::query()->where('username', 'cashier')->firstOrFail();
        $cash = ModeOfPayment::query()
            ->where('mode_of_payment', 'Cash')
            ->firstOrFail();
        $product = FacilityProduct::query()
            ->where('product_code', FacilityProductCode::CottageSmall->value)
            ->firstOrFail();
        $amenity = Amenity::query()
            ->where('amenity_type', 'Rentable')
            ->where('amenity_price', '300.00')
            ->firstOrFail();

        $date = today()->addDays(15)->toDateString();
        $requirements = app(FacilityRequirementService::class);
        $intent = $requirements->createIntent('walk-in-regression', 2);
        $intent = $requirements->replaceGroups($intent, 2, [[
            'facility_product_id' => $product->facility_product_id,
            'quantity' => 1,
            'rate_code' => FacilityRateCode::Day->value,
            'check_in_date' => $date,
            'check_out_date' => $date,
            'estimated_users' => 2,
        ]]);

        $booking = app(FacilityAssignmentService::class)->createStaffBooking($intent, [
            'user_id' => $cashier->user_id,
            'walk_in' => true,
            'first_name' => 'Walk',
            'middle_name' => null,
            'last_name' => 'In',
            'contact_no' => '09123456789',
            'email' => 'walk-in@example.test',
            'province' => 'South Cotabato',
            'city' => 'General Santos City',
            'barangay' => 'Dadiangas',
            'purok' => 'Olaer',
            'payment_amount' => '500.00',
            'mode_of_payment_id' => $cash->mode_of_payment_id,
            'reference_number' => '',
            'room_occupants' => [],
            'entrance' => [
                'adult_count' => 2,
                'children_count' => 0,
                'pwd_sc_count' => 0,
                'male_count' => 1,
                'female_count' => 1,
                'tourist_count' => 0,
                'adult_discount_id' => null,
                'children_discount_id' => null,
                'pwd_sc_discount_id' => null,
                'adult_discounted_quantity' => 0,
                'children_discounted_quantity' => 0,
                'pwd_sc_discounted_quantity' => 0,
            ],
            'amenities' => [[
                'amenity_id' => $amenity->amenity_id,
                'quantity' => 1,
            ]],
        ]);

        $booking->refresh();

        $this->assertSame('Checked-in', $booking->status);
        $this->assertSame('800.00', $booking->total_price);
        $this->assertSame('300.00', $booking->amount_due);
        $this->assertNotNull($booking->entrance_slip_id);

        $this->assertDatabaseHas('tbl_payment', [
            'booking_id' => $booking->booking_id,
            'amount_paid' => 500.00,
            'payment_status' => 'Verified',
            'verified_by_user_id' => $cashier->user_id,
        ]);

        $this->assertDatabaseHas('tbl_entrance_slip', [
            'entrance_slip_id' => $booking->entrance_slip_id,
            'guest_id' => $booking->guest_id,
            'total_price' => 200.00,
            'amount_due' => 0.00,
            'status' => 'Paid',
            'handled_by_user_id' => $cashier->user_id,
            'admitted_by_user_id' => $cashier->user_id,
        ]);

        $this->assertDatabaseHas('tbl_amenity_request', [
            'booking_id' => $booking->booking_id,
            'amenity_request_status' => 'Pending',
            'total_price' => 300.00,
        ]);

        $summary = app(TransactionLedgerService::class)
            ->summaryForBooking($booking->fresh());

        $this->assertSame('800.00', $summary['total']);
        $this->assertSame('500.00', $summary['verified_payments']);
        $this->assertSame('300.00', $summary['balance']);
    }

    public function test_walk_in_rejects_payment_that_does_not_cover_core_facility_and_admission(): void
    {
        $this->seed(DatabaseSeeder::class);

        $cashier = User::query()->where('username', 'cashier')->firstOrFail();
        $cash = ModeOfPayment::query()
            ->where('mode_of_payment', 'Cash')
            ->firstOrFail();
        $product = FacilityProduct::query()
            ->where('product_code', FacilityProductCode::CottageSmall->value)
            ->firstOrFail();

        $date = today()->addDays(16)->toDateString();
        $requirements = app(FacilityRequirementService::class);
        $intent = $requirements->createIntent('walk-in-underpay-regression', 2);
        $intent = $requirements->replaceGroups($intent, 2, [[
            'facility_product_id' => $product->facility_product_id,
            'quantity' => 1,
            'rate_code' => FacilityRateCode::Day->value,
            'check_in_date' => $date,
            'check_out_date' => $date,
            'estimated_users' => 2,
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'requires full payment of all core facility and admission charges',
        );

        app(FacilityAssignmentService::class)->createStaffBooking($intent, [
            'user_id' => $cashier->user_id,
            'walk_in' => true,
            'first_name' => 'Under',
            'last_name' => 'Paid',
            'contact_no' => '09123456780',
            'email' => 'walk-in-underpaid@example.test',
            'province' => 'South Cotabato',
            'city' => 'General Santos City',
            'payment_amount' => '499.00',
            'mode_of_payment_id' => $cash->mode_of_payment_id,
            'reference_number' => '',
            'room_occupants' => [],
            'entrance' => [
                'adult_count' => 2,
                'children_count' => 0,
                'pwd_sc_count' => 0,
                'male_count' => 1,
                'female_count' => 1,
                'tourist_count' => 0,
                'adult_discount_id' => null,
                'children_discount_id' => null,
                'pwd_sc_discount_id' => null,
                'adult_discounted_quantity' => 0,
                'children_discounted_quantity' => 0,
                'pwd_sc_discounted_quantity' => 0,
            ],
            'amenities' => [],
        ]);
    }
}

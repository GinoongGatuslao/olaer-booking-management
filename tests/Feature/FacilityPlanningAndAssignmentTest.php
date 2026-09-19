<?php

namespace Tests\Feature;

use App\FacilityCapacityPolicy;
use App\FacilityProductCode;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\FacilityScheduleBlock;
use App\Models\FacilityType;
use App\Models\ModeOfPayment;
use App\Models\ProductRate;
use App\Models\Role;
use App\Models\User;
use App\Services\FacilityAssignmentService;
use App\Services\FacilityAvailabilityService;
use App\Services\FacilityRequirementService;
use App\Services\ReservationToBookingWorkflowService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

class FacilityPlanningAndAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_owned_plan_assigns_requested_quantity_atomically(): void
    {
        Mail::fake();
        [$product, $facilities] = $this->cottageInventory(3);
        $date = today()->addDays(10)->toDateString();
        $requirements = app(FacilityRequirementService::class);
        $intent = $requirements->createIntent('planner-session', 6);

        $intent = $requirements->replaceGroups($intent, 6, [[
            'facility_product_id' => $product->facility_product_id,
            'quantity' => 2,
            'rate_code' => FacilityRateCode::Day->value,
            'check_in_date' => $date,
            'check_out_date' => $date,
            'estimated_users' => 6,
        ]]);

        $this->assertSame(3, app(FacilityAvailabilityService::class)->availabilityCount(
            $product->facility_product_id,
            FacilityRateCode::Day,
            $date,
            $date,
        ));

        $reservation = app(FacilityAssignmentService::class)->createReservation($intent, [
            'first_name' => 'Plan',
            'last_name' => 'Guest',
            'email' => 'planner@example.test',
            'contact_no' => '09171234567',
            'province' => 'South Cotabato',
            'city' => 'General Santos City',
            'extra_guests' => [],
        ]);

        $this->assertSame('200.00', $reservation->total_price);
        $this->assertCount(2, $reservation->details);
        $this->assertCount(2, $reservation->details->pluck('facility_id')->unique());
        $this->assertSame(6, $reservation->details->sum('guest_count'));
        $this->assertDatabaseCount('tbl_facility_schedule_blocks', 2);
        $this->assertDatabaseHas('tbl_facility_requirement_intents', [
            'facility_requirement_intent_id' => $intent->facility_requirement_intent_id,
            'status' => 'Fulfilled',
            'reservation_id' => $reservation->reservation_id,
        ]);
        $this->assertSame(1, app(FacilityAvailabilityService::class)->availabilityCount(
            $product->facility_product_id,
            FacilityRateCode::Day,
            $date,
            $date,
        ));
        $this->assertTrue($reservation->details->pluck('facility_id')->diff($facilities->modelKeys())->isEmpty());

        $cashierRole = Role::query()->create(['role_name' => 'Cashier']);
        $cashier = User::factory()->create(['role_id' => $cashierRole->role_id]);
        $cash = ModeOfPayment::query()->create(['mode_of_payment' => 'Cash']);
        $booking = app(ReservationToBookingWorkflowService::class)->convert(
            $reservation->reservation_id,
            [
                'user_id' => $cashier->user_id,
                'payment_amount' => $reservation->amount_due,
                'mode_of_payment_id' => $cash->mode_of_payment_id,
                'reference_number' => '',
            ],
        );

        $this->assertCount(2, $booking->details);
        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' => $reservation->reservation_id,
            'status' => 'Converted',
        ]);
        $this->assertSame(2, FacilityScheduleBlock::query()
            ->whereIn('booking_detail_id', $booking->details->modelKeys())
            ->count());
    }

    public function test_plan_token_cannot_be_read_by_another_session(): void
    {
        $requirements = app(FacilityRequirementService::class);
        $intent = $requirements->createIntent('owner-session', 2);

        $this->expectException(InvalidArgumentException::class);
        $requirements->ownedIntent($intent->token, 'attacker-session');
    }

    public function test_cottage_requirement_rejects_a_multi_day_range(): void
    {
        [$product] = $this->cottageInventory(1);
        $requirements = app(FacilityRequirementService::class);
        $intent = $requirements->createIntent('same-day-session', 2);
        $checkIn = today()->addDays(10);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cottages and function halls must use the same start and end date.');

        $requirements->replaceGroups($intent, 2, [[
            'facility_product_id' => $product->facility_product_id,
            'quantity' => 1,
            'rate_code' => FacilityRateCode::Day->value,
            'check_in_date' => $checkIn->toDateString(),
            'check_out_date' => $checkIn->addDay()->toDateString(),
            'estimated_users' => 2,
        ]]);
    }

    /** @return array{FacilityProduct, Collection<int, Facility>} */
    private function cottageInventory(int $quantity): array
    {
        $type = FacilityType::query()->create(['facility_type' => 'Cottage']);
        $product = FacilityProduct::query()->create([
            'product_code' => FacilityProductCode::CottageSmall,
            'facility_type_id' => $type->facility_type_id,
            'display_name' => 'Small Cottage',
            'size_label' => 'Small',
            'schedule_policy' => FacilitySchedulePolicy::DatedSlots,
            'capacity_policy' => FacilityCapacityPolicy::RecommendedInformational,
            'suggested_minimum' => 1,
            'suggested_maximum' => 6,
            'is_active' => true,
        ]);

        foreach ([
            FacilityRateCode::Day->value => ['Day', '100.00'],
            FacilityRateCode::Night->value => ['Night', '120.00'],
            FacilityRateCode::Both->value => ['Both', '200.00'],
        ] as $rateCode => [$displayName, $amount]) {
            ProductRate::query()->create([
                'facility_product_id' => $product->facility_product_id,
                'rate_code' => $rateCode,
                'display_name' => $displayName,
                'amount' => $amount,
                'is_active' => true,
            ]);
        }

        $facilities = new Collection;

        foreach (range(1, $quantity) as $number) {
            $facilities->push(Facility::query()->create([
                'facility_number' => 'COT-SMA-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'facility_name' => 'Small Cottage '.$number,
                'facility_type_id' => $type->facility_type_id,
                'facility_product_id' => $product->facility_product_id,
                'facility_size' => 'Small',
                'facility_status' => 'Available',
                'capacity' => '1-6',
            ]));
        }

        return [$product, $facilities];
    }
}

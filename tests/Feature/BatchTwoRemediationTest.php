<?php

namespace Tests\Feature;

use App\FacilityCapacityPolicy;
use App\FacilityProductCode;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\Facility;
use App\Models\FacilityPrice;
use App\Models\FacilityProduct;
use App\Models\FacilityType;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\ProductRate;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\Role;
use App\Models\User;
use App\Services\BillingStatementService;
use App\Services\BookingWorkflowService;
use App\Services\CashierReservationWorkflowService;
use App\Services\DecimalMoneyService;
use App\Services\FacilityProductConfigurationService;
use App\Services\FacilityScheduleBlockService;
use App\Services\GuestReservationManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class BatchTwoRemediationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_creation_is_authoritative_and_second_committed_conflict_is_rejected(): void
    {
        [$product, $facility] = $this->productAndFacility(FacilityProductCode::RoomStandard, 'Room', [
            FacilityRateCode::Overnight->value => '1000.00',
        ], included: 4, maximum: 6);
        $cashier = $this->cashier();
        $payload = $this->reservationPayload($cashier, $facility, $product, 5, [[
            'first_name' => 'Named',
            'last_name' => 'Extra',
        ]]);

        $reservation = app(CashierReservationWorkflowService::class)->create($payload);

        $this->assertSame('1100.00', $reservation->total_price);
        $this->assertSame($product->facility_product_id, $reservation->details->sole()->facility_product_id);

        try {
            app(CashierReservationWorkflowService::class)->create([
                ...$payload,
                'email' => 'competitor@example.test',
            ]);
            $this->fail('A competing reservation occupied the same facility period.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('no longer available', $exception->getMessage());
        }

        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(1, ReservationDetail::query()->count());
    }

    public function test_reschedule_rejects_cross_product_tampering_and_rechecks_converted_state(): void
    {
        [$roomProduct, $room] = $this->productAndFacility(FacilityProductCode::RoomStandard, 'Room', [
            FacilityRateCode::Overnight->value => '1000.00',
        ], included: 4, maximum: 6);
        [, $cottage] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '300.00',
        ]);
        [, $hall] = $this->productAndFacility(FacilityProductCode::FunctionHall1, 'Function Hall', [
            FacilityRateCode::WholeDay->value => '1200.00',
        ]);
        $cashier = $this->cashier();
        $reservation = app(CashierReservationWorkflowService::class)->create(
            $this->reservationPayload($cashier, $room, $roomProduct),
        );

        foreach ([$cottage, $hall] as $tamperedFacility) {
            try {
                app(CashierReservationWorkflowService::class)->reschedule($reservation->reservation_id, [
                    'user_id' => $cashier->user_id,
                    'facility_id' => $tamperedFacility->facility_id,
                    'rate_type' => $tamperedFacility === $hall ? 'Whole Day' : 'Day',
                    'check_in_date' => '2026-10-12',
                    'check_out_date' => '2026-10-13',
                    'discount_id' => null,
                ]);
                $this->fail('Cross-product reservation tampering was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('same product category', $exception->getMessage());
            }
        }

        Booking::query()->create([
            'b_ref_no' => 'BCONVERTED',
            'guest_id' => $reservation->guest_id,
            'booking_date' => now()->toDateString(),
            'total_price' => '1000.00',
            'amount_due' => '0.00',
            'no_of_extra_guests' => 0,
            'total_guest_count' => 4,
            'reservation_id' => $reservation->reservation_id,
            'status' => 'Booked',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('active, unconverted');
        app(CashierReservationWorkflowService::class)->reschedule($reservation->reservation_id, [
            'user_id' => $cashier->user_id,
            'facility_id' => $room->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => '2026-10-12',
            'check_out_date' => '2026-10-13',
            'discount_id' => null,
        ]);
    }

    public function test_room_reschedule_revalidates_capacity_and_extra_guest_ownership(): void
    {
        [$product, $firstRoom] = $this->productAndFacility(FacilityProductCode::RoomStandard, 'Room', [
            FacilityRateCode::Overnight->value => '1000.00',
        ], included: 4, maximum: 6);
        $secondRoom = $this->facility($product, 'Room', 'Room B');
        $cashier = $this->cashier();
        $reservation = app(CashierReservationWorkflowService::class)->create(
            $this->reservationPayload($cashier, $firstRoom, $product, 5, [[
                'first_name' => 'Correct',
                'last_name' => 'Owner',
            ]]),
        );
        $detail = $reservation->details->sole();
        $reservation->update(['no_of_extra_guests' => 0]);

        try {
            app(CashierReservationWorkflowService::class)->reschedule($reservation->reservation_id, [
                'user_id' => $cashier->user_id,
                'facility_id' => $secondRoom->facility_id,
                'rate_type' => 'Overnight',
                'check_in_date' => '2026-10-12',
                'check_out_date' => '2026-10-13',
                'discount_id' => null,
            ]);
            $this->fail('Cross-detail extra-guest ownership was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('ownership and counts', $exception->getMessage());
        }

        $reservation->update(['no_of_extra_guests' => 1]);
        $product->update(['strict_maximum' => 4]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum of 4');
        app(CashierReservationWorkflowService::class)->reschedule($reservation->reservation_id, [
            'user_id' => $cashier->user_id,
            'facility_id' => $secondRoom->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => '2026-10-12',
            'check_out_date' => '2026-10-13',
            'discount_id' => null,
        ]);
    }

    public function test_authoritative_rate_configuration_persists_only_coherent_canonical_snapshots(): void
    {
        [$roomProduct, $room] = $this->productAndFacility(FacilityProductCode::RoomStandard, 'Room', [
            FacilityRateCode::Overnight->value => '1000.00',
        ], included: 4, maximum: 6);
        [$hallProduct, $hall] = $this->productAndFacility(FacilityProductCode::FunctionHall1, 'Function Hall', [
            FacilityRateCode::WholeDay->value => '1200.00',
        ]);
        [$cottageProduct, $cottage] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '300.00',
            FacilityRateCode::Night->value => '200.00',
            FacilityRateCode::Both->value => '500.00',
        ]);
        $roomProduct->productRates()->update(['display_name' => 'DayFoo']);
        $hallProduct->productRates()->update(['display_name' => 'Night Hall']);
        $cottageProduct->productRates()->get()->each(
            fn (ProductRate $rate): bool => $rate->update(['display_name' => 'Custom '.$rate->rate_code->value]),
        );
        $cashier = $this->cashier();

        foreach ([
            [$roomProduct, $room, 'Day', FacilityRateCode::Overnight, 'Overnight'],
            [$hallProduct, $hall, 'Bogus', FacilityRateCode::WholeDay, 'Whole Day'],
            [$cottageProduct, $cottage, 'Day', FacilityRateCode::Day, 'Day'],
            [$cottageProduct, $cottage, 'Night', FacilityRateCode::Night, 'Night'],
            [$cottageProduct, $cottage, 'Both', FacilityRateCode::Both, 'Both'],
        ] as $index => [$product, $facility, $postedRateType, $expectedCode, $expectedLabel]) {
            $payload = $this->reservationPayload($cashier, $facility, $product);
            $payload['rate_type'] = $postedRateType;
            $payload['email'] = "canonical{$index}@example.test";
            $payload['check_in_date'] = '2027-01-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $payload['check_out_date'] = $product->schedule_policy === FacilitySchedulePolicy::DatedSlots
                ? $payload['check_in_date']
                : '2027-01-'.str_pad((string) ($index + 2), 2, '0', STR_PAD_LEFT);

            $detail = app(CashierReservationWorkflowService::class)->create($payload)->details->sole();

            $this->assertSame($expectedLabel, $detail->rate_type);
            $this->assertSame($expectedCode, $detail->rate_code);
            $this->assertSame($product->facility_product_id, $detail->facility_product_id);
            $this->assertSame($product->schedule_policy, $detail->schedule_policy);
        }

        $payload = $this->reservationPayload($cashier, $cottage, $cottageProduct);
        $payload['rate_type'] = 'DayFoo';
        $payload['check_in_date'] = '2027-02-01';
        $payload['check_out_date'] = '2027-02-01';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('selected rate is not configured');
        app(CashierReservationWorkflowService::class)->create($payload);
    }

    public function test_cashier_cottage_component_uses_canonical_rate_values(): void
    {
        [$product] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '300.00',
            FacilityRateCode::Night->value => '200.00',
            FacilityRateCode::Both->value => '500.00',
        ], suffix: 'CashierCanonical');
        $cashier = $this->cashier();
        $this->actingAs($cashier);

        $date = now()->addDays(10)->toDateString();

        $component = Volt::test('cashier.bookings.create')
            ->set('partyCount', 4)
            ->set('groups.0.facility_product_id', (string) $product->facility_product_id)
            ->set('groups.0.quantity', 1)
            ->set('groups.0.estimated_users', 4)
            ->set('groups.0.check_in_date', $date)
            ->set('groups.0.check_out_date', $date)
            ->call('updatedGroups')
            ->assertSeeHtml('value="DAY"')
            ->assertSeeHtml('value="NIGHT"')
            ->assertSeeHtml('value="BOTH"')
            ->assertDontSeeHtml('value="Day Rate"')
            ->assertDontSeeHtml('value="Night Rate"');

        foreach ([
            [FacilityRateCode::Day, '300.00'],
            [FacilityRateCode::Night, '200.00'],
            [FacilityRateCode::Both, '500.00'],
        ] as [$rateCode, $amount]) {
            $component
                ->set('groups.0.rate_code', $rateCode->value)
                ->call('savePlan')
                ->assertHasNoErrors('plan')
                ->assertSet('planTotal', $amount);
        }

        $bookingCount = Booking::query()->count();

        Volt::test('cashier.bookings.create')
            ->set('partyCount', 4)
            ->set('groups.0.facility_product_id', (string) $product->facility_product_id)
            ->set('groups.0.quantity', 1)
            ->set('groups.0.estimated_users', 4)
            ->set('groups.0.rate_code', 'DayFoo')
            ->set('groups.0.check_in_date', $date)
            ->set('groups.0.check_out_date', $date)
            ->call('savePlan')
            ->assertHasErrors('plan');

        $this->assertSame($bookingCount, Booking::query()->count());
    }

    public function test_guest_cottage_management_uses_rate_code_when_display_name_changes(): void
    {
        Mail::fake();
        [$product, $currentFacility] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '300.00',
            FacilityRateCode::Night->value => '200.00',
            FacilityRateCode::Both->value => '500.00',
        ], suffix: 'GuestCanonical');
        $destinationFacility = $this->facility($product, 'Cottage', 'Guest Canonical Destination');
        $cashier = $this->cashier();
        $reservation = app(CashierReservationWorkflowService::class)->create(
            $this->reservationPayload($cashier, $currentFacility, $product),
        );
        $detail = ReservationDetail::query()->where('reservation_id', $reservation->reservation_id)->sole();
        $product->productRates()->where('rate_code', FacilityRateCode::Day)->update(['display_name' => 'DayFoo']);
        $service = app(GuestReservationManagementService::class);

        $this->assertContains('Day', $service->rateTypesForFacilityType($product->facility_type_id));
        $this->assertNotContains('DayFoo', $service->rateTypesForFacilityType($product->facility_type_id));
        $this->assertTrue($service->availableFacilities(
            $product->facility_type_id,
            'Day',
            '2027-06-01',
            '2027-06-02',
            $detail->reservation_details_id,
        )->contains('facility_id', $destinationFacility->facility_id));
        $this->assertSame('300.00', $service->quotePreview(
            $destinationFacility->facility_id,
            'Day',
            '2027-06-01',
            '2027-06-02',
            totalGuestCount: 4,
        )['total_price']);
        $this->assertTrue($service->availableFacilities(
            $product->facility_type_id,
            'DayFoo',
            '2027-06-01',
            '2027-06-02',
        )->isEmpty());

        $updated = $service->updateReservation($reservation->reservation_id, [
            'facility_id' => $destinationFacility->facility_id,
            'rate_type' => 'Day',
            'check_in_date' => '2027-06-01',
            'check_out_date' => '2027-06-02',
            'total_guest_count' => 4,
            'extra_guests' => [],
        ]);

        $updatedDetail = ReservationDetail::query()->where('reservation_id', $updated->reservation_id)->sole();
        $this->assertSame('Day', $updatedDetail->rate_type);
        $this->assertSame(FacilityRateCode::Day, $updatedDetail->rate_code);

        $this->expectException(InvalidArgumentException::class);
        $service->quotePreview(
            $destinationFacility->facility_id,
            'DayFoo',
            '2027-07-01',
            '2027-07-02',
            totalGuestCount: 4,
        );
    }

    public function test_malformed_normalized_product_configuration_is_not_bookable(): void
    {
        [$roomProduct, $room] = $this->productAndFacility(FacilityProductCode::RoomStandard, 'Room', [
            FacilityRateCode::Overnight->value => '1000.00',
        ], included: 4, maximum: 6, suffix: 'Config');
        [$cottageProduct, $cottage] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '300.00',
            FacilityRateCode::Night->value => '200.00',
            FacilityRateCode::Both->value => '500.00',
        ], suffix: 'Config');
        [$hallProduct, $hall] = $this->productAndFacility(FacilityProductCode::FunctionHall1, 'Function Hall', [
            FacilityRateCode::WholeDay->value => '1200.00',
        ], suffix: 'Config');
        $configuration = app(FacilityProductConfigurationService::class);
        $assertRejected = function (Facility $facility) use ($configuration): void {
            try {
                $configuration->configuredFacility($facility->facility_id);
                $this->fail('Malformed normalized configuration was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('not configured for new transactions', $exception->getMessage());
            }
        };

        $overnight = $roomProduct->productRates()->sole();
        $overnight->update(['is_active' => false]);
        $assertRejected($room);
        $overnight->update(['is_active' => true]);
        $overnight->delete();
        $assertRejected($room);
        $overnight = ProductRate::query()->create([
            'facility_product_id' => $roomProduct->facility_product_id,
            'rate_code' => FacilityRateCode::Overnight,
            'display_name' => 'Anything',
            'amount' => '1000.00',
            'is_active' => true,
        ]);
        $roomProduct->update(['schedule_policy' => FacilitySchedulePolicy::DatedSlots]);
        $assertRejected($room);
        $roomProduct->update(['schedule_policy' => FacilitySchedulePolicy::Overnight]);
        $roomProduct->update(['included_guest_count' => 7]);
        $assertRejected($room);
        $roomProduct->update(['included_guest_count' => 4, 'capacity_policy' => FacilityCapacityPolicy::RecommendedInformational]);
        $assertRejected($room);
        $roomProduct->update(['capacity_policy' => FacilityCapacityPolicy::Strict]);
        $roomProduct->update(['strict_maximum' => null]);
        $assertRejected($room);
        $roomProduct->update(['strict_maximum' => 6]);
        $overnight->update(['amount' => '0.00']);
        $assertRejected($room);

        $dayRate = $cottageProduct->productRates()->where('rate_code', FacilityRateCode::Day)->firstOrFail();
        $dayRate->delete();
        $assertRejected($cottage);
        ProductRate::query()->create([
            'facility_product_id' => $cottageProduct->facility_product_id,
            'rate_code' => FacilityRateCode::Day,
            'display_name' => 'Custom Day',
            'amount' => '300.00',
            'is_active' => true,
        ]);
        foreach ([FacilityRateCode::Day, FacilityRateCode::Night, FacilityRateCode::Both] as $requiredRate) {
            $rate = $cottageProduct->productRates()->where('rate_code', $requiredRate)->firstOrFail();
            $rate->update(['is_active' => false]);
            $assertRejected($cottage);
            $rate->update(['is_active' => true]);
        }
        $cottageProduct->update(['suggested_maximum' => null]);
        $assertRejected($cottage);
        $cottageProduct->update(['suggested_maximum' => 0]);
        $assertRejected($cottage);
        $cottageProduct->update(['suggested_minimum' => 21, 'suggested_maximum' => 20]);
        $assertRejected($cottage);
        $cottageProduct->update([
            'suggested_minimum' => 1,
            'capacity_policy' => FacilityCapacityPolicy::Strict,
        ]);
        $assertRejected($cottage);

        $wholeDay = $hallProduct->productRates()->sole();
        $wholeDay->delete();
        $assertRejected($hall);
        $wholeDay = ProductRate::query()->create([
            'facility_product_id' => $hallProduct->facility_product_id,
            'rate_code' => FacilityRateCode::WholeDay,
            'display_name' => 'Custom Hall Label',
            'amount' => '1200.00',
            'is_active' => true,
        ]);
        $wholeDay->update(['is_active' => false]);
        $assertRejected($hall);
        $wholeDay->update(['is_active' => true]);
        $hallProduct->update(['suggested_maximum' => 0]);
        $assertRejected($hall);
        $hallProduct->update(['suggested_maximum' => 20, 'schedule_policy' => FacilitySchedulePolicy::DatedSlots]);
        $assertRejected($hall);
    }

    public function test_reschedule_rejects_parent_detail_guest_count_mismatches_without_mutation(): void
    {
        [$product, $firstFacility] = $this->productAndFacility(FacilityProductCode::RoomStandard, 'Room', [
            FacilityRateCode::Overnight->value => '1000.00',
        ], included: 6, maximum: 6, suffix: 'Mismatch');

        foreach ([[6, 4], [4, 6]] as $index => [$parentCount, $detailCount]) {
            $facility = $index === 0
                ? $firstFacility
                : $this->facility($product, 'Room', 'Mismatch '.$index);
            $cashier = $this->cashier();
            $reservation = app(CashierReservationWorkflowService::class)->create(
                $this->reservationPayload($cashier, $facility, $product, 6),
            );
            $reservation->update(['total_guest_count' => $parentCount]);
            $reservation->details()->sole()->update(['guest_count' => $detailCount]);
            $reservationBefore = $reservation->fresh()->getRawOriginal();
            $detailBefore = $reservation->details()->sole()->getRawOriginal();

            try {
                app(CashierReservationWorkflowService::class)->reschedule($reservation->reservation_id, [
                    'user_id' => $cashier->user_id,
                    'facility_id' => $facility->facility_id,
                    'rate_type' => 'Overnight',
                    'check_in_date' => '2027-03-01',
                    'check_out_date' => '2027-03-02',
                    'discount_id' => null,
                ]);
                $this->fail('A mismatched parent/detail party size was silently rewritten.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('guest count must be reviewed', $exception->getMessage());
            }

            $this->assertSame($reservationBefore, $reservation->fresh()->getRawOriginal());
            $this->assertSame($detailBefore, $reservation->details()->sole()->getRawOriginal());
        }
    }

    public function test_room_reschedule_preserves_coherent_party_size_and_extra_guest_fee(): void
    {
        [$product, $firstRoom] = $this->productAndFacility(FacilityProductCode::RoomStandard, 'Room', [
            FacilityRateCode::Overnight->value => '1000.00',
        ], included: 4, maximum: 6, suffix: 'PreservedParty');
        $secondRoom = $this->facility($product, 'Room', 'Preserved Party Destination');
        $cashier = $this->cashier();
        $reservation = app(CashierReservationWorkflowService::class)->create(
            $this->reservationPayload($cashier, $firstRoom, $product, 5, [[
                'first_name' => 'Named',
                'last_name' => 'Extra',
            ]]),
        );

        $rescheduled = app(CashierReservationWorkflowService::class)->reschedule($reservation->reservation_id, [
            'user_id' => $cashier->user_id,
            'facility_id' => $secondRoom->facility_id,
            'rate_type' => 'Day',
            'check_in_date' => '2027-04-01',
            'check_out_date' => '2027-04-02',
            'discount_id' => null,
        ]);

        $detail = $rescheduled->details->sole();
        $this->assertSame(5, $rescheduled->total_guest_count);
        $this->assertSame(5, $detail->guest_count);
        $this->assertSame(1, $rescheduled->no_of_extra_guests);
        $this->assertSame('100.00', $detail->extra_guest_fee);
        $this->assertSame('Overnight', $detail->rate_type);
    }

    public function test_reschedule_recalculates_amount_due_from_exact_verified_payment_total(): void
    {
        [$product, $facility] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '0.30',
        ]);
        $cashier = $this->cashier();
        $reservation = app(CashierReservationWorkflowService::class)->create(
            $this->reservationPayload($cashier, $facility, $product),
        );
        $modeId = DB::table('tbl_mode_of_payment')->insertGetId(['mode_of_payment' => 'Cash']);

        $this->reservationPayment($reservation, $modeId, '0.10', 'PEXACT');

        $rescheduled = app(CashierReservationWorkflowService::class)->reschedule($reservation->reservation_id, [
            'user_id' => $cashier->user_id,
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Day',
            'check_in_date' => '2026-10-12',
            'check_out_date' => '2026-10-12',
            'discount_id' => null,
        ]);

        $this->assertSame('0.30', $rescheduled->total_price);
        $this->assertSame('0.20', $rescheduled->amount_due);
        $this->assertSame(4, $rescheduled->total_guest_count);
    }

    public function test_reschedule_rejects_exactly_paid_and_overpaid_totals_without_mutation(): void
    {
        [$product, $firstFacility] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '0.30',
        ], suffix: 'PaidBoundary');

        foreach ([
            ['amounts' => ['0.10', '0.20'], 'suffix' => 'EQUAL'],
            ['amounts' => ['0.10', '0.21'], 'suffix' => 'OVER'],
        ] as $index => $case) {
            $facility = $index === 0
                ? $firstFacility
                : $this->facility($product, 'Cottage', $case['suffix']);
            $cashier = $this->cashier();
            $reservation = app(CashierReservationWorkflowService::class)->create(
                $this->reservationPayload($cashier, $facility, $product),
            );
            $modeId = DB::table('tbl_mode_of_payment')->insertGetId(['mode_of_payment' => 'Cash '.$case['suffix']]);

            foreach ($case['amounts'] as $index => $amount) {
                $this->reservationPayment($reservation, $modeId, $amount, 'P'.$case['suffix'].$index);
            }

            $reservationBefore = $reservation->fresh()->getRawOriginal();
            $detailBefore = $reservation->details()->sole()->getRawOriginal();
            $paymentsBefore = $reservation->payments()->orderBy('payment_id')->get()->map->getRawOriginal()->all();

            try {
                app(CashierReservationWorkflowService::class)->reschedule($reservation->reservation_id, [
                    'user_id' => $cashier->user_id,
                    'facility_id' => $facility->facility_id,
                    'rate_type' => 'Day',
                    'check_in_date' => '2026-10-12',
                    'check_out_date' => '2026-10-12',
                    'discount_id' => null,
                ]);
                $this->fail('A fully paid or overpaid reservation was rescheduled.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'The selected change would make this reservation fully paid or overpaid. Complete the appropriate booking/payment process before rescheduling.',
                    $exception->getMessage(),
                );
            }

            $this->assertSame($reservationBefore, $reservation->fresh()->getRawOriginal());
            $this->assertSame('Active', $reservation->fresh()->status);
            $this->assertSame($detailBefore, $reservation->details()->sole()->getRawOriginal());
            $this->assertSame($paymentsBefore, $reservation->payments()->orderBy('payment_id')->get()->map->getRawOriginal()->all());
        }
    }

    public function test_decimal_money_rejects_float_and_aggregates_exactly(): void
    {
        $money = app(DecimalMoneyService::class);

        $this->assertSame('0.30', $money->add('0.10', '0.20'));
        $this->assertSame('0.00', $money->subtract('0.30', $money->add('0.10', '0.20')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not floats');
        $money->normalize(0.10);
    }

    public function test_billing_reconciles_snapshot_lines_and_verified_payments_without_double_counting(): void
    {
        [$product, $facility] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '0.30',
        ]);
        $booking = $this->booking($facility, $product, FacilityRateCode::Day, '0.30');
        $modeId = DB::table('tbl_mode_of_payment')->insertGetId(['mode_of_payment' => 'Cash']);

        foreach (['0.10', '0.20'] as $index => $amount) {
            Payment::query()->create([
                'p_ref_no' => 'PBILL'.$index,
                'booking_id' => $booking->booking_id,
                'mode_of_payment_id' => $modeId,
                'amount_paid' => $amount,
                'date_paid' => now()->toDateString(),
                'payment_status' => 'Verified',
            ]);
        }

        $statement = app(BillingStatementService::class)->statementForBooking($booking->booking_id);
        $line = $statement['facility_lines']->sole();

        $this->assertSame('0.30', $line['base_price']);
        $this->assertSame('0.00', $line['discount_amount']);
        $this->assertSame('0.00', $line['extra_guest_fee']);
        $this->assertSame('0.30', $line['line_total']);
        $this->assertSame('0.30', $statement['total_price']);
        $this->assertSame('0.30', $statement['total_paid']);
        $this->assertSame('0.00', $statement['amount_due']);
    }

    public function test_current_canonical_cottage_transfers_use_historical_source_rate_and_current_destination_snapshots(): void
    {
        $cashier = $this->cashier();
        $cashModeId = DB::table('tbl_mode_of_payment')->insertGetId(['mode_of_payment' => 'Cash']);

        foreach ([
            [FacilityProductCode::CottageSmall, FacilityProductCode::CottageMedium, FacilityRateCode::Day, '100.00', '150.00'],
            [FacilityProductCode::CottageLarge, FacilityProductCode::CottageExtraLarge, FacilityRateCode::Night, '80.00', '120.00'],
        ] as $index => [$sourceCode, $destinationCode, $rateCode, $sourceAmount, $destinationAmount]) {
            [$oldProduct, $oldFacility] = $this->productAndFacility($sourceCode, 'Cottage', [
                $rateCode->value => $sourceAmount,
            ], suffix: 'CurrentTransfer'.$index);
            [$newProduct, $newFacility] = $this->productAndFacility($destinationCode, 'Cottage', [
                $rateCode->value => $destinationAmount,
            ], suffix: 'CurrentTransfer'.$index);
            $booking = app(BookingWorkflowService::class)->createBooking(
                $this->bookingPayload($cashier, $oldFacility, $rateCode, $sourceAmount, $cashModeId, $index),
            );
            $detail = BookingDetail::query()->where('booking_id', $booking->booking_id)->sole();

            $this->assertSame(app(FacilityProductConfigurationService::class)->canonicalRateType($rateCode), $detail->rate_type);
            $this->assertSame($rateCode, $detail->rate_code);
            $this->assertSame($sourceAmount, $detail->unit_rate);

            $oldProduct->productRates()->where('rate_code', $rateCode)->update(['amount' => '900.00']);

            app(BookingWorkflowService::class)->transferBookingDetail($detail->booking_details_id, $newFacility->facility_id);

            $detail->refresh();
            $booking->refresh();
            $this->assertSame($newProduct->facility_product_id, $detail->facility_product_id);
            $this->assertSame($destinationAmount, $detail->unit_rate);
            $this->assertSame($destinationAmount, $detail->base_price);
            $this->assertSame($destinationAmount, $detail->line_total);
            $this->assertSame($destinationAmount, $booking->total_price);
            $this->assertSame(bcsub($destinationAmount, $sourceAmount, 2), $booking->amount_due);
        }
    }

    public function test_transfer_preserves_complete_historical_discount_after_discount_expiry(): void
    {
        [$oldProduct, $oldFacility] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '100.00',
        ], suffix: 'HistoricalDiscount');
        [$newProduct, $newFacility] = $this->productAndFacility(FacilityProductCode::CottageMedium, 'Cottage', [
            FacilityRateCode::Day->value => '150.00',
        ], suffix: 'HistoricalDiscount');
        $booking = $this->booking($oldFacility, $oldProduct, FacilityRateCode::Day, '100.00');
        $detail = $booking->details()->sole();
        $discountId = DB::table('tbl_discount')->insertGetId([
            'discount_name' => 'Expired historical discount',
            'discount_amount' => '0.20',
            'app_to_cottage' => true,
            'status' => 'Inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $detail->update([
            'discount_id' => $discountId,
            'discount_rate' => '0.200000',
            'discount_amount' => '20.00',
            'line_total' => '80.00',
        ]);
        $booking->update(['total_price' => '80.00', 'amount_due' => '0.00']);

        app(BookingWorkflowService::class)->transferBookingDetail($detail->booking_details_id, $newFacility->facility_id);

        $detail->refresh();
        $booking->refresh();
        $this->assertSame($newProduct->facility_product_id, $detail->facility_product_id);
        $this->assertSame($discountId, $detail->discount_id);
        $this->assertSame('0.200000', $detail->discount_rate);
        $this->assertSame('20.00', $detail->discount_amount);
        $this->assertSame('150.00', $detail->base_price);
        $this->assertSame('130.00', $detail->line_total);
        $this->assertSame('130.00', $booking->total_price);
        $this->assertSame('50.00', $booking->amount_due);
    }

    public function test_transfer_reconstructs_only_complete_historical_lines_and_rejects_ambiguity_atomically(): void
    {
        [$oldProduct, $oldFacility] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '100.00',
        ], suffix: 'TransferProvenance');
        [$newProduct, $newFacility] = $this->productAndFacility(FacilityProductCode::CottageMedium, 'Cottage', [
            FacilityRateCode::Day->value => '150.00',
        ], suffix: 'TransferProvenance');
        $reconstructableBooking = $this->booking($oldFacility, $oldProduct, FacilityRateCode::Day, '100.00');
        $reconstructableDetail = $reconstructableBooking->details()->sole();
        $reconstructableDetail->update(['line_total' => null]);

        app(BookingWorkflowService::class)->transferBookingDetail(
            $reconstructableDetail->booking_details_id,
            $newFacility->facility_id,
        );

        $this->assertSame('150.00', $reconstructableDetail->fresh()->line_total);
        $this->assertSame('0.00', $reconstructableDetail->fresh()->discount_amount);
        $this->assertSame('0.00', $reconstructableDetail->fresh()->extra_guest_fee);

        $secondDestination = $this->facility($newProduct, 'Cottage', 'Second transfer destination');
        $ambiguousBooking = $this->booking($oldFacility, $oldProduct, FacilityRateCode::Day, '100.00');
        $ambiguousDetail = $ambiguousBooking->details()->sole();
        $discountId = DB::table('tbl_discount')->insertGetId([
            'discount_name' => 'Historical transfer discount',
            'discount_amount' => '0.20',
            'app_to_cottage' => true,
            'status' => 'Inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ambiguousDetail->update([
            'discount_id' => $discountId,
            'discount_rate' => '0.200000',
            'discount_amount' => null,
            'line_total' => null,
        ]);
        $ambiguousBooking->update(['total_price' => '80.00', 'amount_due' => '0.00']);
        $bookingBefore = $ambiguousBooking->fresh()->getRawOriginal();
        $detailBefore = $ambiguousDetail->fresh()->getRawOriginal();
        $activityCountBefore = ActivityLog::query()->count();

        try {
            app(BookingWorkflowService::class)->transferBookingDetail(
                $ambiguousDetail->booking_details_id,
                $secondDestination->facility_id,
            );
            $this->fail('An ambiguous historical payable state was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('historical booking price is incomplete', $exception->getMessage());
        }

        $this->assertSame($bookingBefore, $ambiguousBooking->fresh()->getRawOriginal());
        $this->assertSame($detailBefore, $ambiguousDetail->fresh()->getRawOriginal());
        $this->assertSame($activityCountBefore, ActivityLog::query()->count());
    }

    public function test_cottage_day_extension_uses_night_rate_and_becomes_both(): void
    {
        [$product, $facility] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '100.00',
            FacilityRateCode::Night->value => '50.00',
            FacilityRateCode::Both->value => '150.00',
        ]);
        $booking = $this->booking($facility, $product, FacilityRateCode::Day, '100.00');
        $detail = $booking->details()->sole();

        app(BookingWorkflowService::class)->extendCottageDayRate($detail->booking_details_id);

        $detail->refresh();
        $booking->refresh();
        $this->assertSame(FacilityRateCode::Both, $detail->rate_code);
        $this->assertSame('Both', $detail->rate_type);
        $this->assertSame('150.00', $detail->unit_rate);
        $this->assertSame('150.00', $detail->base_price);
        $this->assertSame('0.00', $detail->extra_guest_fee);
        $this->assertSame('150.00', $detail->line_total);
        $this->assertSame('0.000000', $detail->discount_rate);
        $this->assertSame('50.00', $booking->amount_due);
        $this->assertSame(0, $detail->extraGuests()->count());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only Day Rate');
        app(BookingWorkflowService::class)->extendCottageDayRate($detail->booking_details_id);
    }

    public function test_discounted_cottage_extension_preserves_discount_amount_and_adds_full_night_rate(): void
    {
        [$product, $facility] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '100.00',
            FacilityRateCode::Night->value => '50.00',
            FacilityRateCode::Both->value => '150.00',
        ], suffix: 'Discounted');
        $booking = $this->booking($facility, $product, FacilityRateCode::Day, '100.00');
        $detail = $booking->details()->sole();
        $discountId = DB::table('tbl_discount')->insertGetId([
            'discount_name' => 'Historical 20%',
            'discount_amount' => '0.20',
            'app_to_cottage' => true,
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $detail->update([
            'discount_id' => $discountId,
            'discount_rate' => '0.200000',
            'discount_amount' => '20.00',
            'line_total' => '80.00',
        ]);
        $booking->update(['total_price' => '80.00', 'amount_due' => '0.00']);

        app(BookingWorkflowService::class)->extendCottageDayRate($detail->booking_details_id);

        $detail->refresh();
        $booking->refresh();
        $this->assertSame($discountId, $detail->discount_id);
        $this->assertNull($detail->discount_rate);
        $this->assertSame('20.00', $detail->discount_amount);
        $this->assertSame('150.00', $detail->base_price);
        $this->assertSame('0.00', $detail->extra_guest_fee);
        $this->assertSame('130.00', $detail->line_total);
        $this->assertSame('130.00', $booking->total_price);
        $this->assertSame('50.00', $booking->amount_due);
        $this->assertSame(FacilityRateCode::Both, $detail->rate_code);
        $this->assertSame('Both', $detail->rate_type);
        $this->assertSame(0, $detail->extraGuests()->count());

        $product->productRates()->update(['amount' => '900.00']);
        $statement = app(BillingStatementService::class)->statementForBooking($booking->booking_id);
        $line = $statement['facility_lines']->sole();
        $this->assertSame('150.00', $line['base_price']);
        $this->assertSame('20.00', $line['discount_amount']);
        $this->assertSame('0.00', $line['extra_guest_fee']);
        $this->assertSame('130.00', $line['line_total']);
        $this->assertSame('130.00', $statement['total_price']);
        $this->assertSame('50.00', $statement['amount_due']);
    }

    public function test_cottage_extension_reconstructs_null_line_only_from_complete_components(): void
    {
        [$product, $facility] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '100.00',
            FacilityRateCode::Night->value => '50.00',
            FacilityRateCode::Both->value => '150.00',
        ], suffix: 'Reconstructable');
        $booking = $this->booking($facility, $product, FacilityRateCode::Day, '100.00');
        $detail = $booking->details()->sole();
        $detail->update([
            'discount_rate' => '0.200000',
            'discount_amount' => '20.00',
            'line_total' => null,
        ]);
        $booking->update(['total_price' => '80.00', 'amount_due' => '0.00']);

        app(BookingWorkflowService::class)->extendCottageDayRate($detail->booking_details_id);

        $this->assertSame('130.00', $detail->fresh()->line_total);
        $this->assertNull($detail->fresh()->discount_rate);
    }

    public function test_cottage_extension_rejects_incomplete_monetary_provenance_without_mutation(): void
    {
        [$product, $facility] = $this->productAndFacility(FacilityProductCode::CottageSmall, 'Cottage', [
            FacilityRateCode::Day->value => '100.00',
            FacilityRateCode::Night->value => '50.00',
            FacilityRateCode::Both->value => '150.00',
        ], suffix: 'Incomplete');
        $booking = $this->booking($facility, $product, FacilityRateCode::Day, '100.00');
        $detail = $booking->details()->sole();
        $detail->update(['discount_amount' => null, 'line_total' => null]);
        $bookingBefore = $booking->fresh()->getRawOriginal();
        $detailBefore = $detail->fresh()->getRawOriginal();

        try {
            app(BookingWorkflowService::class)->extendCottageDayRate($detail->booking_details_id);
            $this->fail('An incomplete cottage pricing snapshot was extended.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('incomplete', $exception->getMessage());
        }

        $this->assertSame($bookingBefore, $booking->fresh()->getRawOriginal());
        $this->assertSame($detailBefore, $detail->fresh()->getRawOriginal());
    }

    public function test_night_both_and_function_hall_extensions_are_rejected(): void
    {
        foreach ([
            [FacilityRateCode::Night, FacilityProductCode::CottageSmall],
            [FacilityRateCode::Both, FacilityProductCode::CottageMedium],
        ] as [$rateCode, $productCode]) {
            [$product, $facility] = $this->productAndFacility($productCode, 'Cottage', [
                FacilityRateCode::Day->value => '100.00',
                FacilityRateCode::Night->value => '50.00',
                FacilityRateCode::Both->value => '150.00',
            ], suffix: $rateCode->value);
            $detail = $this->booking($facility, $product, $rateCode, $rateCode === FacilityRateCode::Night ? '50.00' : '150.00')->details()->sole();

            try {
                app(BookingWorkflowService::class)->extendCottageDayRate($detail->booking_details_id);
                $this->fail("{$rateCode->value} cottage extension was accepted.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Only Day Rate', $exception->getMessage());
            }
        }

        [$hallProduct, $hall] = $this->productAndFacility(FacilityProductCode::FunctionHall1, 'Function Hall', [
            FacilityRateCode::WholeDay->value => '1200.00',
        ]);
        $hallDetail = $this->booking($hall, $hallProduct, FacilityRateCode::WholeDay, '1200.00')->details()->sole();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only cottage');
        app(BookingWorkflowService::class)->extendCottageDayRate($hallDetail->booking_details_id);
    }

    public function test_corrective_migration_repairs_safe_rows_and_preserves_unrecoverable_booking_values(): void
    {
        $type = FacilityType::query()->create(['facility_type' => 'Room']);
        $unmapped = Facility::query()->create([
            'facility_name' => 'Unmapped',
            'facility_type_id' => $type->facility_type_id,
            'facility_size' => 'Legacy',
            'facility_status' => 'Available',
            'capacity' => '4',
        ]);
        $guest = $this->guest();
        $soleBooking = $this->bareBooking($guest, 'BSOLE', '75.00', 3);
        $soleDetail = $this->bareBookingDetail($soleBooking, $unmapped, '0.00', null);
        $ambiguousBooking = $this->bareBooking($guest, 'BAMBIG', '250.00', 5);
        $firstAmbiguousBookingDetail = $this->bareBookingDetail($ambiguousBooking, $unmapped, '0.00', null);
        $unrelatedBookingDetail = $this->bareBookingDetail($ambiguousBooking, $unmapped, '25.00', null);
        $ambiguousReservation = $this->bareReservation($guest, 'RAMBIG', '300.00', 8);
        $firstAmbiguousReservationDetail = $this->bareReservationDetail($ambiguousReservation, $unmapped, '0.00', null);
        $secondAmbiguousReservationDetail = $this->bareReservationDetail($ambiguousReservation, $unmapped, '0.00', null);
        $legitimateZeroReservation = $this->bareReservation($guest, 'RZERO', '100.00', 2);
        $legitimateZeroDetail = $this->bareReservationDetail($legitimateZeroReservation, $unmapped, '0.00', '100.00');
        $bookingWithAmenity = $this->bareBooking($guest, 'BAMENITY', '130.00', 2);
        $facilityOnlyDetail = $this->bareBookingDetail($bookingWithAmenity, $unmapped, '0.00', '130.00');
        $facilityOnlyDetail->update([
            'base_price' => '100.00',
            'discount_amount' => '0.00',
        ]);
        DB::table('tbl_amenity_request')->insert([
            'booking_id' => $bookingWithAmenity->booking_id,
            'amenity_request_status' => 'Delivered',
            'total_price' => '30.00',
            'date_created' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bookingWithFine = $this->bareBooking($guest, 'BFINE', '125.00', 2);
        $fineContaminatedDetail = $this->bareBookingDetail($bookingWithFine, $unmapped, '0.00', '125.00');
        $fineContaminatedDetail->update(['base_price' => '100.00', 'discount_amount' => '0.00']);
        $fineId = DB::table('tbl_fine')->insertGetId([
            'fine_type' => 'Situational',
            'situational_fine' => 'Test fine',
            'fine_charge' => '25.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tbl_guest_fine')->insert([
            'booking_id' => $bookingWithFine->booking_id,
            'fine_id' => $fineId,
            'quantity' => 1,
            'facility_id' => $unmapped->facility_id,
            'total_charge' => '25.00',
            'date_checked' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nonEquivalentAmenityBooking = $this->bareBooking($guest, 'BAMENITYAMBIG', '130.00', 2);
        $nonEquivalentAmenityDetail = $this->bareBookingDetail($nonEquivalentAmenityBooking, $unmapped, '0.00', '130.00');
        $nonEquivalentAmenityDetail->update(['base_price' => '100.00', 'discount_amount' => '0.00']);
        DB::table('tbl_amenity_request')->insert([
            'booking_id' => $nonEquivalentAmenityBooking->booking_id,
            'amenity_request_status' => 'Delivered',
            'total_price' => '25.00',
            'date_created' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nonEquivalentFineBooking = $this->bareBooking($guest, 'BFINEAMBIG', '130.00', 2);
        $nonEquivalentFineDetail = $this->bareBookingDetail($nonEquivalentFineBooking, $unmapped, '0.00', '130.00');
        $nonEquivalentFineDetail->update(['base_price' => '100.00', 'discount_amount' => '0.00']);
        DB::table('tbl_guest_fine')->insert([
            'booking_id' => $nonEquivalentFineBooking->booking_id,
            'fine_id' => $fineId,
            'quantity' => 1,
            'facility_id' => $unmapped->facility_id,
            'total_charge' => '25.00',
            'date_checked' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $multipleExactBooking = $this->bareBooking($guest, 'BMULTIEXACT', '145.00', 2);
        $multipleExactDetail = $this->bareBookingDetail($multipleExactBooking, $unmapped, '0.00', '145.00');
        $multipleExactDetail->update(['base_price' => '100.00', 'discount_amount' => '0.00']);
        DB::table('tbl_amenity_request')->insert([
            'booking_id' => $multipleExactBooking->booking_id,
            'amenity_request_status' => 'Delivered',
            'total_price' => '20.00',
            'date_created' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tbl_guest_fine')->insert([
            'booking_id' => $multipleExactBooking->booking_id,
            'fine_id' => $fineId,
            'quantity' => 1,
            'facility_id' => $unmapped->facility_id,
            'total_charge' => '25.00',
            'date_checked' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $multipleAmbiguousBooking = $this->bareBooking($guest, 'BMULTIAMBIG', '150.00', 2);
        $multipleAmbiguousDetail = $this->bareBookingDetail($multipleAmbiguousBooking, $unmapped, '0.00', '150.00');
        $multipleAmbiguousDetail->update(['base_price' => '100.00', 'discount_amount' => '0.00']);
        DB::table('tbl_amenity_request')->insert([
            'booking_id' => $multipleAmbiguousBooking->booking_id,
            'amenity_request_status' => 'Delivered',
            'total_price' => '20.00',
            'date_created' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tbl_guest_fine')->insert([
            'booking_id' => $multipleAmbiguousBooking->booking_id,
            'fine_id' => $fineId,
            'quantity' => 1,
            'facility_id' => $unmapped->facility_id,
            'total_charge' => '25.00',
            'date_checked' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nullExactBooking = $this->bareBooking($guest, 'BNULLEXACT', '130.00', 2);
        $nullExactDetail = $this->bareBookingDetail($nullExactBooking, $unmapped, '0.00', null);
        $nullExactDetail->update(['base_price' => '100.00', 'discount_amount' => '0.00']);
        DB::table('tbl_amenity_request')->insert([
            'booking_id' => $nullExactBooking->booking_id,
            'amenity_request_status' => 'Delivered',
            'total_price' => '30.00',
            'date_created' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nullAmbiguousBooking = $this->bareBooking($guest, 'BNULLAMBIG', '130.00', 2);
        $nullAmbiguousDetail = $this->bareBookingDetail($nullAmbiguousBooking, $unmapped, '0.00', null);
        DB::table('tbl_amenity_request')->insert([
            'booking_id' => $nullAmbiguousBooking->booking_id,
            'amenity_request_status' => 'Delivered',
            'total_price' => '30.00',
            'date_created' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $incompleteKnownBooking = $this->bareBooking($guest, 'BINCOMPLETEKNOWN', '130.00', 2);
        $incompleteKnownDetail = $this->bareBookingDetail($incompleteKnownBooking, $unmapped, '0.00', '130.00');
        DB::table('tbl_amenity_request')->insert([
            'booking_id' => $incompleteKnownBooking->booking_id,
            'amenity_request_status' => 'Delivered',
            'total_price' => '30.00',
            'date_created' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $zeroAmenityBooking = $this->bareBooking($guest, 'BZEROAMENITY', '100.00', 2);
        $zeroAmenityDetail = $this->bareBookingDetail($zeroAmenityBooking, $unmapped, '0.00', '100.00');
        DB::table('tbl_amenity_request')->insert([
            'booking_id' => $zeroAmenityBooking->booking_id,
            'amenity_request_status' => 'Delivered',
            'total_price' => '0.00',
            'date_created' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $zeroFineBooking = $this->bareBooking($guest, 'BZEROFINE', '100.00', 2);
        $zeroFineDetail = $this->bareBookingDetail($zeroFineBooking, $unmapped, '0.00', '100.00');
        DB::table('tbl_guest_fine')->insert([
            'booking_id' => $zeroFineBooking->booking_id,
            'fine_id' => $fineId,
            'quantity' => 1,
            'facility_id' => $unmapped->facility_id,
            'total_charge' => '0.00',
            'date_checked' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $noChargesBooking = $this->bareBooking($guest, 'BNOCHARGES', '100.00', 2);
        $noChargesDetail = $this->bareBookingDetail($noChargesBooking, $unmapped, '0.00', '100.00');
        $alreadyCorrectBooking = $this->bareBooking($guest, 'BALREADYCORRECT', '130.00', 2);
        $alreadyCorrectDetail = $this->bareBookingDetail($alreadyCorrectBooking, $unmapped, '0.00', '100.00');
        $alreadyCorrectDetail->update(['base_price' => '100.00', 'discount_amount' => '0.00']);
        DB::table('tbl_amenity_request')->insert([
            'booking_id' => $alreadyCorrectBooking->booking_id,
            'amenity_request_status' => 'Delivered',
            'total_price' => '30.00',
            'date_created' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $countsBefore = [
            'bookings' => Booking::query()->count(),
            'booking_details' => BookingDetail::query()->count(),
            'reservations' => Reservation::query()->count(),
            'reservation_details' => ReservationDetail::query()->count(),
            'amenity_requests' => DB::table('tbl_amenity_request')->count(),
            'guest_fines' => DB::table('tbl_guest_fine')->count(),
        ];
        $preservedMoneyBefore = [
            'booking_total' => $ambiguousBooking->total_price,
            'booking_due' => $ambiguousBooking->amount_due,
            'reservation_total' => $ambiguousReservation->total_price,
            'reservation_due' => $ambiguousReservation->amount_due,
            'amenity_total' => DB::table('tbl_amenity_request')
                ->where('booking_id', $bookingWithAmenity->booking_id)
                ->value('total_price'),
            'fine_total' => DB::table('tbl_guest_fine')
                ->where('booking_id', $bookingWithFine->booking_id)
                ->value('total_charge'),
        ];
        $migration = require database_path('migrations/2026_09_11_080241_correct_batch_two_detail_backfill.php');

        $migration->up();
        $this->assertNull($firstAmbiguousReservationDetail->fresh()->extra_guest_fee);
        $migration->down();
        $this->assertNull($firstAmbiguousReservationDetail->fresh()->extra_guest_fee);
        $migration->up();

        $this->assertSame(3, $soleDetail->fresh()->guest_count);
        $this->assertSame('0.00', $firstAmbiguousBookingDetail->fresh()->extra_guest_fee);
        $this->assertNull($firstAmbiguousReservationDetail->fresh()->extra_guest_fee);
        $this->assertNull($secondAmbiguousReservationDetail->fresh()->extra_guest_fee);
        $this->assertSame('25.00', $unrelatedBookingDetail->fresh()->extra_guest_fee);
        $this->assertSame('0.00', $legitimateZeroDetail->fresh()->extra_guest_fee);
        $this->assertSame('100.00', $facilityOnlyDetail->fresh()->line_total);
        $this->assertSame('100.00', $fineContaminatedDetail->fresh()->line_total);
        $this->assertSame('130.00', $nonEquivalentAmenityDetail->fresh()->line_total);
        $this->assertSame('130.00', $nonEquivalentFineDetail->fresh()->line_total);
        $this->assertSame('100.00', $multipleExactDetail->fresh()->line_total);
        $this->assertSame('150.00', $multipleAmbiguousDetail->fresh()->line_total);
        $this->assertSame('100.00', $nullExactDetail->fresh()->line_total);
        $this->assertNull($nullAmbiguousDetail->fresh()->line_total);
        $this->assertSame('130.00', $incompleteKnownDetail->fresh()->line_total);
        $this->assertSame('100.00', $zeroAmenityDetail->fresh()->line_total);
        $this->assertSame('100.00', $zeroFineDetail->fresh()->line_total);
        $this->assertSame('100.00', $noChargesDetail->fresh()->line_total);
        $this->assertSame('100.00', $alreadyCorrectDetail->fresh()->line_total);
        $this->assertSame($preservedMoneyBefore, [
            'booking_total' => $ambiguousBooking->fresh()->total_price,
            'booking_due' => $ambiguousBooking->fresh()->amount_due,
            'reservation_total' => $ambiguousReservation->fresh()->total_price,
            'reservation_due' => $ambiguousReservation->fresh()->amount_due,
            'amenity_total' => DB::table('tbl_amenity_request')
                ->where('booking_id', $bookingWithAmenity->booking_id)
                ->value('total_price'),
            'fine_total' => DB::table('tbl_guest_fine')
                ->where('booking_id', $bookingWithFine->booking_id)
                ->value('total_charge'),
        ]);
        $this->assertSame($countsBefore, [
            'bookings' => Booking::query()->count(),
            'booking_details' => BookingDetail::query()->count(),
            'reservations' => Reservation::query()->count(),
            'reservation_details' => ReservationDetail::query()->count(),
            'amenity_requests' => DB::table('tbl_amenity_request')->count(),
            'guest_fines' => DB::table('tbl_guest_fine')->count(),
        ]);
        $this->assertTrue(Schema::hasColumn('tbl_reservation_details', 'line_total'));
    }

    /** @return array{FacilityProduct, Facility} */
    private function productAndFacility(
        FacilityProductCode $code,
        string $typeName,
        array $rates,
        ?int $included = null,
        ?int $maximum = null,
        string $suffix = '',
    ): array {
        $type = FacilityType::query()->firstOrCreate(['facility_type' => $typeName]);
        $isRoom = $code === FacilityProductCode::RoomStandard;

        if ($typeName === 'Cottage') {
            $dayRate = $rates[FacilityRateCode::Day->value] ?? (string) reset($rates);
            $nightRate = $rates[FacilityRateCode::Night->value] ?? $dayRate;
            $rates += [
                FacilityRateCode::Day->value => $dayRate,
                FacilityRateCode::Night->value => $nightRate,
                FacilityRateCode::Both->value => bcadd($dayRate, $nightRate, 2),
            ];
        }

        $product = FacilityProduct::query()->create([
            'product_code' => $code,
            'facility_type_id' => $type->facility_type_id,
            'display_name' => $code->value.$suffix,
            'size_label' => $code->value.$suffix,
            'schedule_policy' => $typeName === 'Room' ? FacilitySchedulePolicy::Overnight : ($typeName === 'Cottage' ? FacilitySchedulePolicy::DatedSlots : FacilitySchedulePolicy::WholeCalendarDay),
            'capacity_policy' => $isRoom ? FacilityCapacityPolicy::Strict : FacilityCapacityPolicy::RecommendedInformational,
            'suggested_minimum' => $isRoom ? null : 1,
            'suggested_maximum' => $isRoom ? null : 20,
            'included_guest_count' => $included,
            'strict_maximum' => $maximum,
            'is_active' => true,
        ]);

        foreach ($rates as $codeValue => $amount) {
            ProductRate::query()->create([
                'facility_product_id' => $product->facility_product_id,
                'rate_code' => $codeValue,
                'display_name' => match ($codeValue) {
                    'OVERNIGHT' => 'Overnight',
                    'WHOLE_DAY' => 'Whole Day',
                    default => ucfirst(strtolower($codeValue)),
                },
                'amount' => $amount,
                'is_active' => true,
            ]);
        }

        return [$product, $this->facility($product, $typeName, $code->value.$suffix)];
    }

    private function facility(FacilityProduct $product, string $typeName, string $name): Facility
    {
        $facility = Facility::query()->create([
            'facility_name' => $name.uniqid(),
            'facility_type_id' => $product->facility_type_id,
            'facility_product_id' => $product->facility_product_id,
            'facility_size' => 'Test',
            'facility_status' => 'Available',
            'capacity' => $typeName === 'Room' ? '6' : '20',
        ]);

        foreach ($product->productRates as $rate) {
            FacilityPrice::query()->create([
                'facility_id' => $facility->facility_id,
                'rate_type' => $rate->display_name,
                'facility_price' => $rate->amount,
            ]);
        }

        return $facility;
    }

    private function cashier(): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => 'Cashier']);

        return User::factory()->create(['role_id' => $role->role_id]);
    }

    /** @return array<string, mixed> */
    private function reservationPayload(User $cashier, Facility $facility, FacilityProduct $product, int $guests = 4, array $extraGuests = []): array
    {
        return [
            'user_id' => $cashier->user_id,
            'first_name' => 'Reservation',
            'last_name' => 'Guest',
            'contact_no' => '09171234567',
            'email' => uniqid().'@example.test',
            'city' => 'Tacurong City',
            'province' => 'Sultan Kudarat',
            'facility_type_id' => $product->facility_type_id,
            'facility_id' => $facility->facility_id,
            'rate_type' => $product->schedule_policy === FacilitySchedulePolicy::Overnight ? 'Overnight' : 'Day',
            'check_in_date' => '2026-10-10',
            'check_out_date' => $product->schedule_policy === FacilitySchedulePolicy::DatedSlots ? '2026-10-10' : '2026-10-11',
            'discount_id' => null,
            'total_guest_count' => $guests,
            'extra_guests' => $extraGuests,
        ];
    }

    /** @return array<string, mixed> */
    private function bookingPayload(
        User $cashier,
        Facility $facility,
        FacilityRateCode $rateCode,
        string $amount,
        int $cashModeId,
        int $dayOffset,
    ): array {
        return [
            'facility_id' => $facility->facility_id,
            'rate_type' => app(FacilityProductConfigurationService::class)->canonicalRateType($rateCode),
            'check_in_date' => now()->addDays($dayOffset + 30)->toDateString(),
            'check_out_date' => now()->addDays($dayOffset + 31)->toDateString(),
            'total_guest_count' => 4,
            'extra_guests' => [],
            'payment_amount' => $amount,
            'mode_of_payment_id' => $cashModeId,
            'reference_number' => '',
            'first_name' => 'Current',
            'last_name' => 'Transfer Guest',
            'contact_no' => '09171234567',
            'email' => "current-transfer-{$dayOffset}@example.test",
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'user_id' => $cashier->user_id,
        ];
    }

    private function booking(Facility $facility, FacilityProduct $product, FacilityRateCode $rateCode, string $amount): Booking
    {
        $guest = $this->guest();
        $booking = $this->bareBooking($guest, 'B'.uniqid(), $amount, 4);
        $detail = BookingDetail::query()->create([
            'booking_id' => $booking->booking_id,
            'facility_id' => $facility->facility_id,
            'facility_product_id' => $product->facility_product_id,
            'guest_count' => 4,
            'capacity_policy' => $product->capacity_policy,
            'suggested_minimum_snapshot' => $product->suggested_minimum,
            'suggested_maximum_snapshot' => $product->suggested_maximum,
            'schedule_policy' => $product->schedule_policy,
            'rate_code' => $rateCode,
            'unit_rate' => $amount,
            'rate_type' => match ($rateCode) {
                FacilityRateCode::WholeDay => 'Whole Day',
                default => ucfirst(strtolower($rateCode->value)),
            },
            'check_in_date' => '2026-11-10',
            'check_out_date' => '2026-11-11',
            'status' => 'Booked',
            'discount_rate' => '0.000000',
            'discount_amount' => '0.00',
            'base_price' => $amount,
            'extra_guest_fee' => '0.00',
            'line_total' => $amount,
        ]);

        app(FacilityScheduleBlockService::class)->acquireForBookingDetail($detail);

        return $booking;
    }

    private function reservationPayment(Reservation $reservation, int $modeId, string $amount, string $reference): Payment
    {
        return Payment::query()->create([
            'p_ref_no' => $reference,
            'reservation_id' => $reservation->reservation_id,
            'mode_of_payment_id' => $modeId,
            'amount_paid' => $amount,
            'date_paid' => now()->toDateString(),
            'payment_status' => 'Verified',
        ]);
    }

    private function guest(): Guest
    {
        $address = Address::query()->create(['province' => 'Sultan Kudarat', 'city' => 'Tacurong City']);

        return Guest::query()->create([
            'first_name' => 'Test',
            'last_name' => 'Guest',
            'contact_no' => '09171234567',
            'email' => uniqid().'@example.test',
            'address_id' => $address->address_id,
        ]);
    }

    private function bareBooking(Guest $guest, string $reference, string $total, int $guestCount): Booking
    {
        return Booking::query()->create([
            'b_ref_no' => $reference,
            'guest_id' => $guest->guest_id,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_guest_count' => $guestCount,
            'total_price' => $total,
            'amount_due' => '0.00',
            'status' => 'Booked',
        ]);
    }

    private function bareReservation(Guest $guest, string $reference, string $total, int $guestCount): Reservation
    {
        return Reservation::query()->create([
            'r_ref_no' => $reference,
            'guest_id' => $guest->guest_id,
            'reservation_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_guest_count' => $guestCount,
            'total_price' => $total,
            'amount_due' => $total,
            'status' => 'Active',
        ]);
    }

    private function bareBookingDetail(Booking $booking, Facility $facility, string $extraGuestFee, ?string $lineTotal): BookingDetail
    {
        return BookingDetail::query()->create([
            'booking_id' => $booking->booking_id,
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Legacy',
            'check_in_date' => '2026-12-01',
            'check_out_date' => '2026-12-02',
            'status' => 'Booked',
            'extra_guest_fee' => $extraGuestFee,
            'line_total' => $lineTotal,
        ]);
    }

    private function bareReservationDetail(Reservation $reservation, Facility $facility, string $extraGuestFee, ?string $lineTotal): ReservationDetail
    {
        return ReservationDetail::query()->create([
            'reservation_id' => $reservation->reservation_id,
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Legacy',
            'check_in_date' => '2026-12-01',
            'check_out_date' => '2026-12-02',
            'extra_guest_fee' => $extraGuestFee,
            'line_total' => $lineTotal,
        ]);
    }
}

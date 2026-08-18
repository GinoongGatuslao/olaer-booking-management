<?php

namespace Tests\Feature;

use App\FacilityCapacityPolicy;
use App\FacilityProductCode;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\Address;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\BookingExtraGuest;
use App\Models\Facility;
use App\Models\FacilityPrice;
use App\Models\FacilityProduct;
use App\Models\FacilityType;
use App\Models\Guest;
use App\Models\ModeOfPayment;
use App\Models\ProductRate;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\User;
use App\Services\BillingStatementService;
use App\Services\BookingQuoteService;
use App\Services\DetailExtraGuestService;
use App\Services\FacilityOccupancyService;
use App\Services\PublicBookingWorkflowService;
use App\Services\PublicReservationWorkflowService;
use App\Services\ReservationQuoteService;
use App\Services\ReservationToBookingWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class BatchTwoDetailSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_single_details_and_does_not_guess_ambiguous_allocations(): void
    {
        [, $room] = $this->createFacilityProduct(
            FacilityProductCode::RoomStandard,
            'Room',
            FacilityRateCode::Overnight,
            'Overnight',
            '2500.00',
            includedGuests: 4,
            strictMaximum: 10,
        );
        [, $cottage] = $this->createFacilityProduct(
            FacilityProductCode::CottageSmall,
            'Cottage',
            FacilityRateCode::Day,
            'Day Rate',
            '300.00',
            suggestedMinimum: 4,
            suggestedMaximum: 6,
        );
        $guest = $this->createGuest();
        $booking = $this->createBooking($guest, 7, '2800.00', 3);
        $bookingDetail = BookingDetail::query()->create([
            'booking_id' => $booking->booking_id,
            'facility_id' => $room->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'status' => 'Booked',
            'base_price' => '2500.00',
            'discount_amount' => '0.00',
            'extra_guest_fee' => '300.00',
            'line_total' => '2800.00',
        ]);
        $extraGuest = BookingExtraGuest::query()->create([
            'booking_id' => $booking->booking_id,
            'first_name' => 'Named',
            'last_name' => 'Extra',
        ]);
        $reservation = $this->createReservation($guest, 20, '300.00');
        $reservationDetail = ReservationDetail::query()->create([
            'reservation_id' => $reservation->reservation_id,
            'facility_id' => $cottage->facility_id,
            'rate_type' => 'Day Rate',
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
        ]);
        $ambiguousBooking = $this->createBooking($guest, 20, '600.00');

        foreach ([4, 5] as $days) {
            BookingDetail::query()->create([
                'booking_id' => $ambiguousBooking->booking_id,
                'facility_id' => $cottage->facility_id,
                'rate_type' => 'Day Rate',
                'check_in_date' => now()->addDays($days)->toDateString(),
                'check_out_date' => now()->addDays($days)->toDateString(),
                'status' => 'Booked',
            ]);
        }

        $migration = require database_path(
            'migrations/2026_08_18_101649_backfill_detail_occupancy_and_pricing_snapshots.php',
        );
        $migration->up();

        $bookingDetail->refresh();
        $reservationDetail->refresh();
        $extraGuest->refresh();

        $this->assertSame(7, $bookingDetail->guest_count);
        $this->assertSame(4, $bookingDetail->included_guest_count_snapshot);
        $this->assertSame('2800.00', $bookingDetail->line_total);
        $this->assertSame($bookingDetail->booking_details_id, $extraGuest->booking_details_id);
        $this->assertSame(20, $reservationDetail->guest_count);
        $this->assertSame('300.00', $reservationDetail->line_total);
        $this->assertSame('300.00', $reservationDetail->unit_rate);
        $this->assertSame('2800.00', $booking->fresh()->total_price);
        $this->assertSame('300.00', $reservation->fresh()->total_price);
        $this->assertSame(
            2,
            BookingDetail::query()
                ->where('booking_id', $ambiguousBooking->booking_id)
                ->whereNull('guest_count')
                ->count(),
        );
    }

    public function test_batch_two_schema_rollback_preserves_transaction_and_guest_rows(): void
    {
        [, $room] = $this->createFacilityProduct(
            FacilityProductCode::RoomStandard,
            'Room',
            FacilityRateCode::Overnight,
            'Overnight',
            '2500.00',
            includedGuests: 4,
            strictMaximum: 10,
        );
        $booking = $this->createBooking($this->createGuest(), 5, '2600.00', 1);
        $detail = $this->completeBookingDetail($booking, $room, 5, '2600.00');
        BookingExtraGuest::query()->create([
            'booking_id' => $booking->booking_id,
            'booking_details_id' => $detail->booking_details_id,
            'first_name' => 'Rollback',
            'last_name' => 'Guest',
        ]);
        $schemaMigration = require database_path(
            'migrations/2026_08_18_101648_add_detail_occupancy_and_pricing_snapshots.php',
        );

        $schemaMigration->down();

        $this->assertFalse(Schema::hasColumn('tbl_booking_details', 'facility_product_id'));
        $this->assertSame(1, DB::table('tbl_booking')->count());
        $this->assertSame(1, DB::table('tbl_booking_details')->count());
        $this->assertSame(1, DB::table('tbl_booking_extra_guests')->count());
    }

    public function test_capacity_policies_apply_room_limits_without_capping_cottage_or_hall_estimates(): void
    {
        [, $room] = $this->createFacilityProduct(
            FacilityProductCode::RoomStandard,
            'Room',
            FacilityRateCode::Overnight,
            'Overnight',
            '2500.00',
            includedGuests: 4,
            strictMaximum: 10,
        );
        [, $cottage] = $this->createFacilityProduct(
            FacilityProductCode::CottageSmall,
            'Cottage',
            FacilityRateCode::Day,
            'Day Rate',
            '300.00',
            suggestedMinimum: 4,
            suggestedMaximum: 6,
        );
        [, $hall] = $this->createFacilityProduct(
            FacilityProductCode::FunctionHall1,
            'Function Hall',
            FacilityRateCode::WholeDay,
            'Day Rate',
            '1200.00',
            suggestedMaximum: 25,
        );
        $bookingQuotes = app(BookingQuoteService::class);
        $reservationQuotes = app(ReservationQuoteService::class);
        $occupancy = app(FacilityOccupancyService::class);

        $roomQuote = $bookingQuotes->quote(
            $room->facility_id,
            'Overnight',
            totalGuestCount: 10,
        );
        $this->assertSame(6, $roomQuote['extra_guest_count']);
        $this->assertSame('600.00', $roomQuote['extra_guest_fee']);
        $this->assertSame('3100.00', $roomQuote['total']);

        foreach ([0, 11] as $invalidRoomCount) {
            try {
                $bookingQuotes->quote(
                    $room->facility_id,
                    'Overnight',
                    totalGuestCount: $invalidRoomCount,
                );
                $this->fail("Room guest count {$invalidRoomCount} was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $cottageQuote = $bookingQuotes->quote(
            $cottage->facility_id,
            'Day Rate',
            totalGuestCount: 20,
        );
        $hallQuote = $reservationQuotes->quote(
            $hall->facility_id,
            'Day Rate',
            now()->addDay()->toDateString(),
            now()->addDay()->toDateString(),
            totalGuestCount: 30,
        );
        $this->assertSame(0, $cottageQuote['extra_guest_count']);
        $this->assertSame('0.00', $cottageQuote['extra_guest_fee']);
        $this->assertSame('1200.00', $hallQuote['total_price']);
        $this->assertSame('0.00', $hallQuote['extra_guest_charge']);
        $this->assertSame(20, $occupancy->forFacility($cottage, 20, 20)['guest_count']);
        $this->assertSame(20, $occupancy->forFacility($hall, 20, 20)['guest_count']);

        $this->expectException(InvalidArgumentException::class);
        $occupancy->forFacility($hall, 30, 20);
    }

    public function test_new_single_detail_workflows_write_complete_immutable_snapshots_and_detail_guests(): void
    {
        Mail::fake();
        [$product, $room] = $this->createFacilityProduct(
            FacilityProductCode::RoomStandard,
            'Room',
            FacilityRateCode::Overnight,
            'Overnight',
            '2500.00',
            includedGuests: 4,
            strictMaximum: 10,
        );
        ModeOfPayment::query()->create(['mode_of_payment' => 'GCash']);

        $booking = app(PublicBookingWorkflowService::class)
            ->createGuestBookingWithPendingGcash([
                ...$this->guestPayload('booking-snapshot@example.test'),
                'facility_id' => $room->facility_id,
                'rate_type' => 'Overnight',
                'check_in_date' => now()->addDay()->toDateString(),
                'check_out_date' => now()->addDays(2)->toDateString(),
                'check_in_time' => '14:00',
                'total_guest_count' => 5,
                'extra_guests' => [[
                    'first_name' => 'Booking',
                    'last_name' => 'Extra',
                ]],
                'payment_amount' => '2600.00',
                'reference_number' => 'BATCH2-BOOKING-REF',
                'proof_of_payment_path' => 'gcash-proofs/batch-2.pdf',
                'facility_product_id' => 999999,
                'detail_snapshot' => ['line_total' => '1.00'],
            ]);
        $bookingDetail = $booking->details->sole();
        $bookingExtraGuest = $booking->extraGuests->sole();

        $this->assertCompleteRoomSnapshot($bookingDetail, $product, 5, '2600.00');
        $this->assertSame($bookingDetail->booking_details_id, $bookingExtraGuest->booking_details_id);

        $reservation = app(PublicReservationWorkflowService::class)
            ->createGuestReservation([
                ...$this->guestPayload('reservation-snapshot@example.test'),
                'facility_id' => $room->facility_id,
                'rate_type' => 'Overnight',
                'check_in_date' => now()->addDays(3)->toDateString(),
                'check_out_date' => now()->addDays(4)->toDateString(),
                'total_guest_count' => 6,
                'extra_guests' => [
                    ['first_name' => 'First', 'last_name' => 'Extra'],
                    ['first_name' => 'Second', 'last_name' => 'Extra'],
                ],
                'facility_product_id' => 999999,
                'detail_snapshot' => ['line_total' => '1.00'],
            ]);
        $reservationDetail = $reservation->details->sole();

        $this->assertCompleteRoomSnapshot($reservationDetail, $product, 6, '2700.00');
        $this->assertSame(
            [$reservationDetail->reservation_details_id],
            $reservation->extraGuests->pluck('reservation_details_id')->unique()->values()->all(),
        );

        $product->productRates()->update(['amount' => '9999.00']);
        $product->update(['included_guest_count' => 2, 'strict_maximum' => 5]);

        $this->assertCompleteRoomSnapshot($bookingDetail->fresh(), $product, 5, '2600.00', 4, 10, '2500.00');
        $this->assertCompleteRoomSnapshot($reservationDetail->fresh(), $product, 6, '2700.00', 4, 10, '2500.00');

        $statement = app(BillingStatementService::class)
            ->statementForBooking($booking->booking_id);
        $this->assertSame(2500.0, $statement['facility_lines']->sole()['base_price']);
    }

    public function test_extra_guest_ownership_rejects_cross_parent_and_non_room_tampering(): void
    {
        [$roomProduct, $room] = $this->createFacilityProduct(
            FacilityProductCode::RoomStandard,
            'Room',
            FacilityRateCode::Overnight,
            'Overnight',
            '2500.00',
            includedGuests: 4,
            strictMaximum: 10,
        );
        [, $cottage] = $this->createFacilityProduct(
            FacilityProductCode::CottageSmall,
            'Cottage',
            FacilityRateCode::Day,
            'Day Rate',
            '300.00',
            suggestedMinimum: 4,
            suggestedMaximum: 6,
        );
        $guest = $this->createGuest();
        $firstBooking = $this->createBooking($guest, 5, '2600.00', 1);
        $secondBooking = $this->createBooking($guest, 5, '2600.00', 1);
        $firstDetail = $this->completeBookingDetail($firstBooking, $room, 5, '2600.00');
        $secondDetail = $this->completeBookingDetail($secondBooking, $room, 5, '2600.00');

        try {
            app(DetailExtraGuestService::class)->createForBooking(
                $firstBooking,
                $secondDetail,
                [['first_name' => 'Cross', 'last_name' => 'Parent']],
            );
            $this->fail('A cross-booking detail association was accepted.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $cottageBooking = $this->createBooking($guest, 20, '300.00');
        $cottageDetail = $this->completeBookingDetail(
            $cottageBooking,
            $cottage,
            20,
            '300.00',
        );

        $this->expectException(InvalidArgumentException::class);
        app(DetailExtraGuestService::class)->createForBooking(
            $cottageBooking,
            $cottageDetail,
            [['first_name' => 'Invalid', 'last_name' => 'Extra']],
        );
    }

    public function test_reservation_conversion_copies_snapshots_without_repricing(): void
    {
        Mail::fake();
        [$product, $room] = $this->createFacilityProduct(
            FacilityProductCode::RoomStandard,
            'Room',
            FacilityRateCode::Overnight,
            'Overnight',
            '2500.00',
            includedGuests: 4,
            strictMaximum: 10,
        );
        $reservation = app(PublicReservationWorkflowService::class)
            ->createGuestReservation([
                ...$this->guestPayload('conversion@example.test'),
                'facility_id' => $room->facility_id,
                'rate_type' => 'Overnight',
                'check_in_date' => now()->addDays(2)->toDateString(),
                'check_out_date' => now()->addDays(3)->toDateString(),
                'total_guest_count' => 6,
                'extra_guests' => [
                    ['first_name' => 'One', 'last_name' => 'Extra'],
                    ['first_name' => 'Two', 'last_name' => 'Extra'],
                ],
            ]);
        $reservationDetail = $reservation->details->sole();
        $product->productRates()->update(['amount' => '9000.00']);
        $product->update(['included_guest_count' => 1, 'strict_maximum' => 6]);
        $cashier = $this->createCashier();
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
        $bookingDetail = $booking->details->sole();

        foreach ([
            'facility_product_id',
            'guest_count',
            'included_guest_count_snapshot',
            'strict_maximum_snapshot',
            'suggested_minimum_snapshot',
            'suggested_maximum_snapshot',
            'unit_rate',
            'base_price',
            'discount_rate',
            'discount_amount',
            'extra_guest_fee',
            'line_total',
        ] as $attribute) {
            $this->assertSame(
                $reservationDetail->getRawOriginal($attribute),
                $bookingDetail->getRawOriginal($attribute),
                "Conversion changed {$attribute}.",
            );
        }

        $this->assertSame(
            [$bookingDetail->booking_details_id],
            $booking->extraGuests->pluck('booking_details_id')->unique()->values()->all(),
        );
        $this->assertSame('2700.00', $bookingDetail->line_total);
    }

    public function test_livewire_room_validation_preserves_the_visible_form_and_entered_count(): void
    {
        Mail::fake();
        [$product, $room] = $this->createFacilityProduct(
            FacilityProductCode::RoomStandard,
            'Room',
            FacilityRateCode::Overnight,
            'Overnight',
            '2500.00',
            includedGuests: 4,
            strictMaximum: 10,
        );

        Livewire::test('guest.reservations.create')
            ->set('first_name', 'Visible')
            ->set('last_name', 'Guest')
            ->set('email', 'visible@example.test')
            ->set('contact_no', '09171234567')
            ->set('province', 'Sultan Kudarat')
            ->set('city', 'Tacurong City')
            ->set('facility_type_id', $product->facility_type_id)
            ->set('rate_type', 'Overnight')
            ->set('check_in_date', now()->addDay()->toDateString())
            ->set('check_out_date', now()->addDays(2)->toDateString())
            ->set('facility_id', $room->facility_id)
            ->set('total_guest_count', 11)
            ->call('save')
            ->assertHasErrors(['total_guest_count' => 'max'])
            ->assertSet('total_guest_count', 11)
            ->assertSee('Guest capacity');
    }

    public function test_unmapped_facilities_are_rejected_for_new_quotes_but_legacy_rows_remain_readable(): void
    {
        $type = FacilityType::query()->create(['facility_type' => 'Room']);
        $facility = Facility::query()->create([
            'facility_name' => 'Legacy Unmapped Room',
            'facility_type_id' => $type->facility_type_id,
            'facility_product_id' => null,
            'facility_size' => 'Standard',
            'facility_status' => 'Available',
            'capacity' => '10',
        ]);
        FacilityPrice::query()->create([
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Overnight',
            'facility_price' => '1500.00',
        ]);
        $reservation = $this->createReservation($this->createGuest(), 4, '1500.00');
        $detail = ReservationDetail::query()->create([
            'reservation_id' => $reservation->reservation_id,
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertSame('Legacy Unmapped Room', $detail->fresh('facility')->facility->facility_name);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not configured for new transactions');
        app(BookingQuoteService::class)->quote(
            $facility->facility_id,
            'Overnight',
            totalGuestCount: 4,
        );
    }

    /**
     * @return array{FacilityProduct, Facility}
     */
    private function createFacilityProduct(
        FacilityProductCode $productCode,
        string $facilityTypeName,
        FacilityRateCode $rateCode,
        string $legacyRateType,
        string $amount,
        ?int $suggestedMinimum = null,
        ?int $suggestedMaximum = null,
        ?int $includedGuests = null,
        ?int $strictMaximum = null,
    ): array {
        $facilityType = FacilityType::query()->firstOrCreate([
            'facility_type' => $facilityTypeName,
        ]);
        $isRoom = $productCode === FacilityProductCode::RoomStandard;
        $product = FacilityProduct::query()->create([
            'product_code' => $productCode,
            'facility_type_id' => $facilityType->facility_type_id,
            'display_name' => Str::headline($productCode->value),
            'size_label' => Str::headline($productCode->value),
            'schedule_policy' => $isRoom
                ? FacilitySchedulePolicy::Overnight
                : ($facilityTypeName === 'Cottage'
                    ? FacilitySchedulePolicy::DatedSlots
                    : FacilitySchedulePolicy::WholeCalendarDay),
            'capacity_policy' => $isRoom
                ? FacilityCapacityPolicy::Strict
                : FacilityCapacityPolicy::RecommendedInformational,
            'suggested_minimum' => $suggestedMinimum,
            'suggested_maximum' => $suggestedMaximum,
            'included_guest_count' => $includedGuests,
            'strict_maximum' => $strictMaximum,
            'is_active' => true,
        ]);
        ProductRate::query()->create([
            'facility_product_id' => $product->facility_product_id,
            'rate_code' => $rateCode,
            'display_name' => $legacyRateType,
            'amount' => $amount,
            'is_active' => true,
        ]);
        $facility = Facility::query()->create([
            'facility_name' => Str::headline($productCode->value).' '.Str::random(5),
            'facility_type_id' => $facilityType->facility_type_id,
            'facility_product_id' => $product->facility_product_id,
            'facility_size' => Str::headline($productCode->value),
            'facility_status' => 'Available',
            'capacity' => $strictMaximum !== null
                ? "{$includedGuests} default / {$strictMaximum} max"
                : (string) ($suggestedMaximum ?? 1),
        ]);
        FacilityPrice::query()->create([
            'facility_id' => $facility->facility_id,
            'rate_type' => $legacyRateType,
            'facility_price' => $amount,
        ]);

        return [$product, $facility];
    }

    private function createGuest(): Guest
    {
        $address = Address::query()->create([
            'purok' => '1',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'barangay' => 'Poblacion',
        ]);

        return Guest::query()->create([
            'first_name' => 'Batch',
            'last_name' => 'Guest',
            'contact_no' => '09171234567',
            'email' => Str::random(8).'@example.test',
            'address_id' => $address->address_id,
        ]);
    }

    private function createBooking(
        Guest $guest,
        int $guestCount,
        string $total,
        int $extraGuestCount = 0,
    ): Booking {
        return Booking::query()->create([
            'b_ref_no' => 'B'.strtoupper(Str::random(12)),
            'guest_id' => $guest->guest_id,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => $extraGuestCount,
            'total_guest_count' => $guestCount,
            'total_price' => $total,
            'amount_due' => $total,
            'status' => 'Booked',
        ]);
    }

    private function createReservation(
        Guest $guest,
        int $guestCount,
        string $total,
    ): Reservation {
        return Reservation::query()->create([
            'r_ref_no' => 'R'.strtoupper(Str::random(12)),
            'guest_id' => $guest->guest_id,
            'reservation_date' => now()->toDateString(),
            'total_price' => $total,
            'amount_due' => $total,
            'no_of_extra_guests' => 0,
            'total_guest_count' => $guestCount,
            'status' => 'Active',
        ]);
    }

    private function completeBookingDetail(
        Booking $booking,
        Facility $facility,
        int $guestCount,
        string $lineTotal,
    ): BookingDetail {
        $product = $facility->facilityProduct()->firstOrFail();
        $rate = $product->productRates()->firstOrFail();
        $isRoom = $product->product_code === FacilityProductCode::RoomStandard;

        return BookingDetail::query()->create([
            'booking_id' => $booking->booking_id,
            'facility_id' => $facility->facility_id,
            'facility_product_id' => $product->facility_product_id,
            'guest_count' => $guestCount,
            'capacity_policy' => $product->capacity_policy,
            'included_guest_count_snapshot' => $product->included_guest_count,
            'strict_maximum_snapshot' => $product->strict_maximum,
            'suggested_minimum_snapshot' => $product->suggested_minimum,
            'suggested_maximum_snapshot' => $product->suggested_maximum,
            'schedule_policy' => $product->schedule_policy,
            'rate_code' => $rate->rate_code,
            'unit_rate' => $rate->amount,
            'rate_type' => $rate->display_name,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'status' => 'Booked',
            'base_price' => $rate->amount,
            'discount_rate' => '0.000000',
            'discount_amount' => '0.00',
            'extra_guest_fee' => $isRoom
                ? (string) (max(0, $guestCount - 4) * 100)
                : '0.00',
            'line_total' => $lineTotal,
        ]);
    }

    private function createCashier(): User
    {
        $roleId = DB::table('tbl_role')->insertGetId([
            'role_name' => 'Cashier',
        ]);
        $address = Address::query()->create([
            'purok' => '1',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'barangay' => 'Poblacion',
        ]);

        return User::query()->create([
            'first_name' => 'Batch',
            'last_name' => 'Cashier',
            'username' => 'batch-cashier-'.Str::random(5),
            'password' => bcrypt('password'),
            'email' => Str::random(8).'@example.test',
            'contact_no' => '09171234567',
            'status' => 'Active',
            'address_id' => $address->address_id,
            'role_id' => $roleId,
        ]);
    }

    /** @return array<string, string|null> */
    private function guestPayload(string $email): array
    {
        return [
            'first_name' => 'Snapshot',
            'middle_name' => null,
            'last_name' => 'Guest',
            'email' => $email,
            'contact_no' => '09171234567',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'barangay' => 'Poblacion',
            'purok' => '1',
        ];
    }

    private function assertCompleteRoomSnapshot(
        BookingDetail|ReservationDetail $detail,
        FacilityProduct $currentProduct,
        int $guestCount,
        string $lineTotal,
        int $includedGuests = 4,
        int $strictMaximum = 10,
        string $unitRate = '2500.00',
    ): void {
        $this->assertSame($currentProduct->facility_product_id, $detail->facility_product_id);
        $this->assertSame($guestCount, $detail->guest_count);
        $this->assertSame(FacilityCapacityPolicy::Strict, $detail->capacity_policy);
        $this->assertSame($includedGuests, $detail->included_guest_count_snapshot);
        $this->assertSame($strictMaximum, $detail->strict_maximum_snapshot);
        $this->assertSame(FacilitySchedulePolicy::Overnight, $detail->schedule_policy);
        $this->assertSame(FacilityRateCode::Overnight, $detail->rate_code);
        $this->assertSame($unitRate, $detail->unit_rate);
        $this->assertNotNull($detail->base_price);
        $this->assertNotNull($detail->discount_rate);
        $this->assertNotNull($detail->discount_amount);
        $this->assertNotNull($detail->extra_guest_fee);
        $this->assertSame($lineTotal, $detail->line_total);
    }
}

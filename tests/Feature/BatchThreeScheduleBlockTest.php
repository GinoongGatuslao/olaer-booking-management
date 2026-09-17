<?php

namespace Tests\Feature;

use App\FacilityCapacityPolicy;
use App\FacilityProductCode;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\FacilityScheduleSlot;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\FacilityScheduleBlock;
use App\Models\FacilityType;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Models\ProductRate;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\Role;
use App\Models\User;
use App\Services\BookingWorkflowService;
use App\Services\CashierReservationWorkflowService;
use App\Services\FacilityScheduleBlockService;
use App\Services\GcashPaymentVerificationService;
use App\Services\GuestReservationManagementService;
use App\Services\PublicBookingWorkflowService;
use App\Services\ReservationNoShowReleaseService;
use App\Services\ReservationToBookingWorkflowService;
use App\Services\StaffReservationCancellationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class BatchThreeScheduleBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_block_generation_uses_machine_slots_and_room_half_open_dates(): void
    {
        $service = app(FacilityScheduleBlockService::class);

        $this->assertSame([
            ['facility_id' => 7, 'service_date' => '2026-09-20', 'slot' => FacilityScheduleSlot::Overnight],
        ], $service->requiredBlocks(
            7,
            FacilitySchedulePolicy::Overnight,
            FacilityRateCode::Overnight,
            '2026-09-20',
            '2026-09-21',
        ));

        $this->assertSame([
            ['facility_id' => 7, 'service_date' => '2026-09-20', 'slot' => FacilityScheduleSlot::Overnight],
            ['facility_id' => 7, 'service_date' => '2026-09-21', 'slot' => FacilityScheduleSlot::Overnight],
            ['facility_id' => 7, 'service_date' => '2026-09-22', 'slot' => FacilityScheduleSlot::Overnight],
        ], $service->requiredBlocks(
            7,
            FacilitySchedulePolicy::Overnight,
            FacilityRateCode::Overnight,
            '2026-09-20',
            '2026-09-23',
        ));

        foreach ([
            [FacilityRateCode::Day, [FacilityScheduleSlot::Day]],
            [FacilityRateCode::Night, [FacilityScheduleSlot::Night]],
            [FacilityRateCode::Both, [FacilityScheduleSlot::Day, FacilityScheduleSlot::Night]],
        ] as [$rateCode, $expectedSlots]) {
            $this->assertSame(
                $expectedSlots,
                collect($service->requiredBlocks(
                    8,
                    FacilitySchedulePolicy::DatedSlots,
                    $rateCode,
                    '2026-09-20',
                    '2026-09-21',
                ))->pluck('slot')->all(),
            );
        }

        $this->assertSame([
            '2026-09-20|day',
            '2026-09-20|night',
            '2026-09-21|day',
            '2026-09-21|night',
        ], collect($service->requiredBlocks(
            8,
            FacilitySchedulePolicy::DatedSlots,
            FacilityRateCode::Both,
            '2026-09-20',
            '2026-09-22',
        ))->map(fn (array $block): string => $block['service_date'].'|'.$block['slot']->value)->all());

        $this->assertSame(
            FacilityScheduleSlot::WholeDay,
            $service->requiredBlocks(
                9,
                FacilitySchedulePolicy::WholeCalendarDay,
                FacilityRateCode::WholeDay,
                '2026-09-20',
                '2026-09-20',
            )[0]['slot'],
        );

        $this->assertSame(
            ['2026-09-20', '2026-09-21'],
            collect($service->requiredBlocks(
                9,
                FacilitySchedulePolicy::WholeCalendarDay,
                FacilityRateCode::WholeDay,
                '2026-09-20',
                '2026-09-22',
            ))->pluck('service_date')->all(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('after check-in');
        $service->requiredBlocks(
            7,
            FacilitySchedulePolicy::Overnight,
            FacilityRateCode::Overnight,
            '2026-09-20',
            '2026-09-20',
        );
    }

    public function test_schema_enforces_unique_slots_exactly_one_owner_and_restrictive_foreign_keys(): void
    {
        [, $facility] = $this->room();
        $cashier = $this->cashier();
        $reservation = app(CashierReservationWorkflowService::class)->create(
            $this->reservationPayload($cashier, $facility, 'Overnight', '2026-09-20', '2026-09-21'),
        );
        $detail = $this->reservationDetail($reservation);
        $block = FacilityScheduleBlock::query()->sole();
        $indexes = collect(Schema::getIndexes('tbl_facility_schedule_blocks'))->keyBy('name');

        $this->assertTrue((bool) $indexes->get('uq_facility_schedule_slot')['unique']);
        $this->assertTrue($indexes->has('tbl_facility_schedule_blocks_reservation_detail_id_index'));
        $this->assertTrue($indexes->has('tbl_facility_schedule_blocks_booking_detail_id_index'));

        $this->assertQueryFails(fn () => DB::table('tbl_facility_schedule_blocks')->insert([
            'facility_id' => $block->facility_id,
            'service_date' => $block->service_date->toDateString(),
            'slot' => $block->slot->value,
            'reservation_detail_id' => $detail->reservation_details_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertQueryFails(fn () => DB::table('tbl_facility_schedule_blocks')->insert([
            'facility_id' => $facility->facility_id,
            'service_date' => '2026-09-30',
            'slot' => FacilityScheduleSlot::Overnight->value,
            'reservation_detail_id' => null,
            'booking_detail_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $cash = ModeOfPayment::query()->create(['mode_of_payment' => 'Cash']);
        $booking = app(BookingWorkflowService::class)->createBooking(
            $this->bookingPayload($cashier, $facility, 'Overnight', '1000.00', $cash, '2026-10-01', '2026-10-02'),
        );

        $this->assertQueryFails(fn () => DB::table('tbl_facility_schedule_blocks')
            ->where('facility_schedule_block_id', $block->facility_schedule_block_id)
            ->update([
                'booking_detail_id' => $this->bookingDetail($booking)->booking_details_id,
            ]));

        $this->assertQueryFails(fn () => ReservationDetail::query()
            ->whereKey($detail->reservation_details_id)
            ->delete());
    }

    public function test_room_creation_rejects_overlap_rolls_back_and_allows_adjacent_stays(): void
    {
        [, $facility] = $this->room();
        $cashier = $this->cashier();
        $service = app(CashierReservationWorkflowService::class);

        $service->create($this->reservationPayload($cashier, $facility, 'Overnight', '2026-09-20', '2026-09-22'));

        try {
            $service->create($this->reservationPayload($cashier, $facility, 'Overnight', '2026-09-21', '2026-09-23'));
            $this->fail('An overlapping room reservation was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('no longer available', $exception->getMessage());
        }

        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(1, ReservationDetail::query()->count());
        $this->assertSame(2, FacilityScheduleBlock::query()->count());

        $service->create($this->reservationPayload($cashier, $facility, 'Overnight', '2026-09-22', '2026-09-23'));

        $this->assertSame(2, Reservation::query()->count());
        $this->assertSame(3, FacilityScheduleBlock::query()->count());
        $this->assertFalse(FacilityScheduleBlock::query()->whereDate('service_date', '2026-09-23')->exists());
    }

    public function test_cottage_day_and_night_are_independent_while_both_conflicts_with_either(): void
    {
        [, $facility] = $this->cottage();
        $cashier = $this->cashier();
        $service = app(CashierReservationWorkflowService::class);

        $service->create($this->reservationPayload($cashier, $facility, 'Day', '2026-10-01', '2026-10-01'));
        $service->create($this->reservationPayload($cashier, $facility, 'Night', '2026-10-01', '2026-10-01'));

        $this->assertSame(
            [FacilityScheduleSlot::Day, FacilityScheduleSlot::Night],
            FacilityScheduleBlock::query()
                ->get()
                ->pluck('slot')
                ->sortBy(fn (FacilityScheduleSlot $slot): int => $slot === FacilityScheduleSlot::Day ? 0 : 1)
                ->values()
                ->all(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no longer available');
        $service->create($this->reservationPayload($cashier, $facility, 'Both', '2026-10-01', '2026-10-01'));
    }

    public function test_function_hall_has_one_whole_day_block_and_rejects_same_date_collision(): void
    {
        [, $facility] = $this->hall();
        $cashier = $this->cashier();
        $service = app(CashierReservationWorkflowService::class);

        $service->create($this->reservationPayload($cashier, $facility, 'Whole Day', '2026-10-02', '2026-10-02'));

        $this->assertDatabaseHas('tbl_facility_schedule_blocks', [
            'facility_id' => $facility->facility_id,
            'service_date' => '2026-10-02',
            'slot' => FacilityScheduleSlot::WholeDay->value,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $service->create($this->reservationPayload($cashier, $facility, 'Whole Day', '2026-10-02', '2026-10-02'));
    }

    public function test_reschedule_preserves_old_assignment_on_conflict_and_syncs_successful_changes(): void
    {
        [$product, $source] = $this->room();
        $target = $this->facility($product, 'Room target');
        $cashier = $this->cashier();
        $service = app(CashierReservationWorkflowService::class);
        $reservation = $service->create($this->reservationPayload($cashier, $source, 'Overnight', '2026-11-01', '2026-11-03'));

        $service->reschedule($reservation->reservation_id, [
            'user_id' => $cashier->user_id,
            'facility_id' => $source->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => '2026-11-04',
            'check_out_date' => '2026-11-06',
            'discount_id' => null,
        ]);

        $detail = $this->reservationDetail($reservation);
        $this->assertSame(['2026-11-04', '2026-11-05'], $this->ownedDates($detail));

        $service->reschedule($reservation->reservation_id, [
            'user_id' => $cashier->user_id,
            'facility_id' => $source->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => '2026-11-04',
            'check_out_date' => '2026-11-06',
            'discount_id' => null,
        ]);
        $this->assertSame(2, FacilityScheduleBlock::query()->count());

        $service->create($this->reservationPayload($cashier, $target, 'Overnight', '2026-11-08', '2026-11-10'));

        try {
            $service->reschedule($reservation->reservation_id, [
                'user_id' => $cashier->user_id,
                'facility_id' => $target->facility_id,
                'rate_type' => 'Overnight',
                'check_in_date' => '2026-11-08',
                'check_out_date' => '2026-11-10',
                'discount_id' => null,
            ]);
            $this->fail('A conflicting reschedule was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('no longer available', $exception->getMessage());
        }

        $detail->refresh();
        $this->assertSame($source->facility_id, $detail->facility_id);
        $this->assertSame(['2026-11-04', '2026-11-05'], $this->ownedDates($detail));
    }

    public function test_conversion_transfers_exact_block_ownership_and_rollback_preserves_reservation_owner(): void
    {
        [, $facility] = $this->room();
        $cashier = $this->cashier();
        $reservation = app(CashierReservationWorkflowService::class)->create(
            $this->reservationPayload($cashier, $facility, 'Overnight', '2026-12-01', '2026-12-03'),
        );
        $detail = $this->reservationDetail($reservation);
        $originalKeys = $this->blockKeys(FacilityScheduleBlock::query()
            ->where('reservation_detail_id', $detail->reservation_details_id)
            ->get());
        $cash = ModeOfPayment::query()->create(['mode_of_payment' => 'Cash']);
        $service = app(ReservationToBookingWorkflowService::class);

        try {
            $service->convert($reservation->reservation_id, [
                'user_id' => $cashier->user_id,
                'payment_amount' => '1.00',
                'mode_of_payment_id' => $cash->mode_of_payment_id,
                'reference_number' => '',
            ]);
            $this->fail('An underpaid conversion was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, DB::table('tbl_booking')->count());
        }

        $this->assertSame(2, FacilityScheduleBlock::query()
            ->where('reservation_detail_id', $detail->reservation_details_id)
            ->count());

        $booking = $service->convert($reservation->reservation_id, [
            'user_id' => $cashier->user_id,
            'payment_amount' => $reservation->amount_due,
            'mode_of_payment_id' => $cash->mode_of_payment_id,
            'reference_number' => '',
        ]);
        $bookingDetail = $this->bookingDetail($booking);

        $this->assertFalse(FacilityScheduleBlock::query()
            ->where('reservation_detail_id', $detail->reservation_details_id)
            ->exists());
        $this->assertSame($originalKeys, $this->blockKeys(FacilityScheduleBlock::query()
            ->where('booking_detail_id', $bookingDetail->booking_details_id)
            ->get()));
    }

    public function test_transfer_and_cottage_extension_change_blocks_atomically(): void
    {
        [$roomProduct, $source] = $this->room();
        $occupiedTarget = $this->facility($roomProduct, 'Occupied target');
        $freeTarget = $this->facility($roomProduct, 'Free target');
        $cashier = $this->cashier();
        $cash = ModeOfPayment::query()->create(['mode_of_payment' => 'Cash']);
        $bookings = app(BookingWorkflowService::class);
        $booking = $bookings->createBooking($this->bookingPayload($cashier, $source, 'Overnight', '1000.00', $cash, '2027-01-01', '2027-01-02'));
        $bookings->createBooking($this->bookingPayload($cashier, $occupiedTarget, 'Overnight', '1000.00', $cash, '2027-01-01', '2027-01-02'));
        $detail = $this->bookingDetail($booking);
        $beforeBooking = $booking->getRawOriginal();

        try {
            $bookings->transferBookingDetail($detail->booking_details_id, $occupiedTarget->facility_id);
            $this->fail('A conflicting transfer was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('no longer available', $exception->getMessage());
        }

        $this->assertSame($source->facility_id, $detail->fresh()->facility_id);
        $this->assertSame($beforeBooking, $booking->fresh()->getRawOriginal());
        $this->assertDatabaseHas('tbl_facility_schedule_blocks', [
            'facility_id' => $source->facility_id,
            'booking_detail_id' => $detail->booking_details_id,
        ]);

        $bookings->transferBookingDetail($detail->booking_details_id, $freeTarget->facility_id);
        $this->assertDatabaseMissing('tbl_facility_schedule_blocks', [
            'facility_id' => $source->facility_id,
            'booking_detail_id' => $detail->booking_details_id,
        ]);
        $this->assertDatabaseHas('tbl_facility_schedule_blocks', [
            'facility_id' => $freeTarget->facility_id,
            'booking_detail_id' => $detail->booking_details_id,
        ]);

        [, $cottage] = $this->cottage();
        $dayBooking = $bookings->createBooking($this->bookingPayload($cashier, $cottage, 'Day', '100.00', $cash, '2027-02-01', '2027-02-01'));
        $dayDetail = $this->bookingDetail($dayBooking);
        $bookings->extendCottageDayRate($dayDetail->booking_details_id);

        $this->assertSame(
            [FacilityScheduleSlot::Day, FacilityScheduleSlot::Night],
            FacilityScheduleBlock::query()
                ->where('booking_detail_id', $dayDetail->booking_details_id)
                ->get()
                ->pluck('slot')
                ->sortBy(fn (FacilityScheduleSlot $slot): int => $slot === FacilityScheduleSlot::Day ? 0 : 1)
                ->values()
                ->all(),
        );
        $this->assertSame(FacilityRateCode::Both, $dayDetail->fresh()->rate_code);

        $this->expectException(InvalidArgumentException::class);
        $bookings->extendCottageDayRate($dayDetail->booking_details_id);
    }

    public function test_cottage_extension_collision_leaves_day_and_financial_state_unchanged(): void
    {
        [, $cottage] = $this->cottage();
        $cashier = $this->cashier();
        $cash = ModeOfPayment::query()->create(['mode_of_payment' => 'Cash']);
        $booking = app(BookingWorkflowService::class)->createBooking($this->bookingPayload($cashier, $cottage, 'Day', '100.00', $cash, '2027-03-01', '2027-03-01'));
        app(CashierReservationWorkflowService::class)->create(
            $this->reservationPayload($cashier, $cottage, 'Night', '2027-03-01', '2027-03-01'),
        );
        $detail = $this->bookingDetail($booking);
        $bookingBefore = $booking->getRawOriginal();
        $detailBefore = $detail->getRawOriginal();

        try {
            app(BookingWorkflowService::class)->extendCottageDayRate($detail->booking_details_id);
            $this->fail('A cottage extension acquired an occupied Night slot.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('no longer available', $exception->getMessage());
        }

        $this->assertSame($bookingBefore, $booking->fresh()->getRawOriginal());
        $this->assertSame($detailBefore, $detail->fresh()->getRawOriginal());
        $this->assertSame(1, FacilityScheduleBlock::query()
            ->where('booking_detail_id', $detail->booking_details_id)
            ->where('slot', FacilityScheduleSlot::Day)
            ->count());
    }

    public function test_current_reservation_release_paths_delete_blocks_in_their_state_transaction(): void
    {
        Mail::fake();
        [$product, $staffFacility] = $this->room();
        $guestFacility = $this->facility($product, 'Guest cancellation');
        $noShowFacility = $this->facility($product, 'No show');
        $staffFailureFacility = $this->facility($product, 'Failed staff cancellation');
        $guestFailureFacility = $this->facility($product, 'Failed guest cancellation');
        $futureFacility = $this->facility($product, 'Future active reservation');
        $cashier = $this->cashier();
        $workflow = app(CashierReservationWorkflowService::class);
        $staff = $workflow->create($this->reservationPayload($cashier, $staffFacility, 'Overnight', '2027-04-01', '2027-04-02'));
        $guest = $workflow->create($this->reservationPayload($cashier, $guestFacility, 'Overnight', '2027-04-03', '2027-04-04'));
        $noShow = $workflow->create($this->reservationPayload($cashier, $noShowFacility, 'Overnight', '2026-01-01', '2026-01-02'));
        $staffFailure = $workflow->create($this->reservationPayload($cashier, $staffFailureFacility, 'Overnight', '2027-04-05', '2027-04-06'));
        $guestFailure = $workflow->create($this->reservationPayload($cashier, $guestFailureFacility, 'Overnight', '2027-04-07', '2027-04-08'));
        $future = $workflow->create($this->reservationPayload($cashier, $futureFacility, 'Overnight', '2027-06-01', '2027-06-02'));
        $cash = ModeOfPayment::query()->create(['mode_of_payment' => 'Cash']);

        foreach ([$staffFailure, $guestFailure] as $paidReservation) {
            Payment::query()->create([
                'p_ref_no' => 'P'.uniqid(),
                'reservation_id' => $paidReservation->reservation_id,
                'mode_of_payment_id' => $cash->mode_of_payment_id,
                'amount_paid' => '1000.00',
                'date_paid' => now()->toDateString(),
                'payment_status' => 'Verified',
            ]);
        }

        app(StaffReservationCancellationService::class)->cancel($staff->reservation_id, 'Guest changed plans.', $cashier->user_id);
        app(GuestReservationManagementService::class)->cancelReservation($guest->reservation_id, 'Guest changed plans.');

        try {
            app(StaffReservationCancellationService::class)->cancel($staffFailure->reservation_id, 'Guest changed plans.', $cashier->user_id);
            $this->fail('A paid reservation was cancelled by staff.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('verified payment', $exception->getMessage());
        }

        try {
            app(GuestReservationManagementService::class)->cancelReservation($guestFailure->reservation_id, 'Guest changed plans.');
            $this->fail('A paid reservation was cancelled by the guest.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('verified payment', $exception->getMessage());
        }

        app(ReservationNoShowReleaseService::class)->expirePastUnpaidReservations('2026-09-17');

        foreach ([$staff, $guest, $noShow] as $reservation) {
            $this->assertFalse(FacilityScheduleBlock::query()
                ->whereIn('reservation_detail_id', $reservation->details()->pluck('reservation_details_id'))
                ->exists());
        }
        $this->assertSame('Cancelled', $staff->fresh()->status);
        $this->assertSame('Cancelled', $guest->fresh()->status);
        $this->assertSame('No-show', $noShow->fresh()->status);

        foreach ([$staffFailure, $guestFailure, $future] as $activeReservation) {
            $this->assertSame('Active', $activeReservation->fresh()->status);
            $this->assertTrue(FacilityScheduleBlock::query()
                ->whereIn('reservation_detail_id', $activeReservation->details()->pluck('reservation_details_id'))
                ->exists());
        }
    }

    public function test_rejected_pending_booking_releases_its_blocks(): void
    {
        Mail::fake();
        [, $facility] = $this->room();
        $cashier = $this->cashier();
        ModeOfPayment::query()->create(['mode_of_payment' => 'GCash']);
        $booking = app(PublicBookingWorkflowService::class)->createGuestBookingWithPendingGcash([
            'first_name' => 'Pending',
            'last_name' => 'Guest',
            'email' => 'pending@example.test',
            'contact_no' => '09171234567',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => '2027-05-01',
            'check_out_date' => '2027-05-02',
            'check_in_time' => '12:00',
            'total_guest_count' => 4,
            'extra_guests' => [],
            'payment_amount' => '1000.00',
            'reference_number' => 'BATCH3-GCASH-1',
            'proof_of_payment_path' => 'gcash-proofs/batch3.pdf',
        ]);
        $detail = $this->bookingDetail($booking);

        $this->assertDatabaseHas('tbl_facility_schedule_blocks', [
            'booking_detail_id' => $detail->booking_details_id,
        ]);

        try {
            app(GcashPaymentVerificationService::class)->reject(
                Payment::query()->where('booking_id', $booking->booking_id)->sole()->payment_id,
                $cashier->user_id,
                '',
            );
            $this->fail('A GCash payment was rejected without a reason.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('reason is required', $exception->getMessage());
        }

        $this->assertDatabaseHas('tbl_facility_schedule_blocks', [
            'booking_detail_id' => $detail->booking_details_id,
        ]);

        app(GcashPaymentVerificationService::class)->reject(
            Payment::query()->where('booking_id', $booking->booking_id)->sole()->payment_id,
            $cashier->user_id,
            'Unreadable proof.',
        );

        $this->assertSame('Payment Rejected', $booking->fresh()->status);
        $this->assertFalse(FacilityScheduleBlock::query()
            ->where('booking_detail_id', $detail->booking_details_id)
            ->exists());
    }

    public function test_successful_gcash_verification_keeps_active_booking_blocks(): void
    {
        Mail::fake();
        [, $facility] = $this->room();
        $cashier = $this->cashier();
        ModeOfPayment::query()->create(['mode_of_payment' => 'GCash']);
        $booking = app(PublicBookingWorkflowService::class)->createGuestBookingWithPendingGcash([
            'first_name' => 'Verified',
            'last_name' => 'Guest',
            'email' => 'verified@example.test',
            'contact_no' => '09171234567',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => '2027-05-03',
            'check_out_date' => '2027-05-04',
            'check_in_time' => '12:00',
            'total_guest_count' => 4,
            'extra_guests' => [],
            'payment_amount' => '1000.00',
            'reference_number' => 'BATCH3-GCASH-2',
            'proof_of_payment_path' => 'gcash-proofs/batch3-verified.pdf',
        ]);
        $detail = $this->bookingDetail($booking);

        app(GcashPaymentVerificationService::class)->verify(
            Payment::query()->where('booking_id', $booking->booking_id)->sole()->payment_id,
            $cashier->user_id,
        );

        $this->assertSame('Booked', $booking->fresh()->status);
        $this->assertDatabaseHas('tbl_facility_schedule_blocks', [
            'booking_detail_id' => $detail->booking_details_id,
        ]);
    }

    /** @return array{FacilityProduct, Facility} */
    private function room(): array
    {
        return $this->productAndFacility(
            FacilityProductCode::RoomStandard,
            'Room',
            FacilitySchedulePolicy::Overnight,
            [FacilityRateCode::Overnight->value => '1000.00'],
        );
    }

    /** @return array{FacilityProduct, Facility} */
    private function cottage(): array
    {
        return $this->productAndFacility(
            FacilityProductCode::CottageSmall,
            'Cottage',
            FacilitySchedulePolicy::DatedSlots,
            [
                FacilityRateCode::Day->value => '100.00',
                FacilityRateCode::Night->value => '50.00',
                FacilityRateCode::Both->value => '150.00',
            ],
        );
    }

    /** @return array{FacilityProduct, Facility} */
    private function hall(): array
    {
        return $this->productAndFacility(
            FacilityProductCode::FunctionHall1,
            'Function Hall',
            FacilitySchedulePolicy::WholeCalendarDay,
            [FacilityRateCode::WholeDay->value => '1200.00'],
        );
    }

    /**
     * @param  array<string, string>  $rates
     * @return array{FacilityProduct, Facility}
     */
    private function productAndFacility(
        FacilityProductCode $productCode,
        string $typeName,
        FacilitySchedulePolicy $schedulePolicy,
        array $rates,
    ): array {
        $type = FacilityType::query()->create(['facility_type' => $typeName]);
        $isRoom = $schedulePolicy === FacilitySchedulePolicy::Overnight;
        $product = FacilityProduct::query()->create([
            'product_code' => $productCode,
            'facility_type_id' => $type->facility_type_id,
            'display_name' => $typeName.' product',
            'size_label' => 'Standard',
            'schedule_policy' => $schedulePolicy,
            'capacity_policy' => $isRoom
                ? FacilityCapacityPolicy::Strict
                : FacilityCapacityPolicy::RecommendedInformational,
            'suggested_minimum' => $isRoom ? null : 1,
            'suggested_maximum' => $isRoom ? null : 20,
            'included_guest_count' => $isRoom ? 4 : null,
            'strict_maximum' => $isRoom ? 10 : null,
            'is_active' => true,
        ]);

        foreach ($rates as $rateCode => $amount) {
            ProductRate::query()->create([
                'facility_product_id' => $product->facility_product_id,
                'rate_code' => $rateCode,
                'display_name' => str_replace('_', ' ', $rateCode),
                'amount' => $amount,
                'is_active' => true,
            ]);
        }

        return [$product, $this->facility($product, $typeName.' 1')];
    }

    private function facility(FacilityProduct $product, string $name): Facility
    {
        return Facility::query()->create([
            'facility_name' => $name.' '.uniqid(),
            'facility_type_id' => $product->facility_type_id,
            'facility_product_id' => $product->facility_product_id,
            'facility_size' => 'Standard',
            'facility_status' => 'Available',
            'capacity' => '10',
        ]);
    }

    private function cashier(): User
    {
        $role = Role::query()->firstOrCreate(['role_name' => 'Cashier']);

        return User::factory()->create(['role_id' => $role->role_id]);
    }

    /** @return array<string, mixed> */
    private function reservationPayload(
        User $cashier,
        Facility $facility,
        string $rateType,
        string $checkIn,
        string $checkOut,
    ): array {
        return [
            'user_id' => $cashier->user_id,
            'first_name' => 'Batch',
            'last_name' => 'Three',
            'contact_no' => '09171234567',
            'email' => uniqid().'@example.test',
            'city' => 'Tacurong City',
            'province' => 'Sultan Kudarat',
            'facility_type_id' => $facility->facility_type_id,
            'facility_id' => $facility->facility_id,
            'rate_type' => $rateType,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'discount_id' => null,
            'total_guest_count' => 4,
            'extra_guests' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function bookingPayload(
        User $cashier,
        Facility $facility,
        string $rateType,
        string $amount,
        ModeOfPayment $mode,
        string $checkIn,
        string $checkOut,
    ): array {
        return [
            'facility_id' => $facility->facility_id,
            'rate_type' => $rateType,
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'check_in_time' => '12:00',
            'discount_id' => null,
            'total_guest_count' => 4,
            'extra_guests' => [],
            'payment_amount' => $amount,
            'mode_of_payment_id' => $mode->mode_of_payment_id,
            'reference_number' => '',
            'first_name' => 'Booking',
            'last_name' => 'Guest',
            'contact_no' => '09171234567',
            'email' => uniqid().'@example.test',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'user_id' => $cashier->user_id,
        ];
    }

    /** @return array<int, string> */
    private function ownedDates(ReservationDetail $detail): array
    {
        return FacilityScheduleBlock::query()
            ->where('reservation_detail_id', $detail->reservation_details_id)
            ->orderBy('service_date')
            ->pluck('service_date')
            ->map(fn (mixed $date): string => substr((string) $date, 0, 10))
            ->all();
    }

    private function reservationDetail(Reservation $reservation): ReservationDetail
    {
        return ReservationDetail::query()
            ->where('reservation_id', $reservation->reservation_id)
            ->sole();
    }

    private function bookingDetail(Booking $booking): BookingDetail
    {
        return BookingDetail::query()
            ->where('booking_id', $booking->booking_id)
            ->sole();
    }

    /**
     * @param  iterable<int, FacilityScheduleBlock>  $blocks
     * @return array<int, string>
     */
    private function blockKeys(iterable $blocks): array
    {
        return collect($blocks)
            ->map(fn (FacilityScheduleBlock $block): string => implode('|', [
                $block->facility_id,
                $block->service_date->toDateString(),
                $block->slot->value,
            ]))
            ->sort()
            ->values()
            ->all();
    }

    private function assertQueryFails(callable $callback): void
    {
        try {
            $callback();
            $this->fail('The database accepted an invalid schedule block mutation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}

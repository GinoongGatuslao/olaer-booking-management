<?php

namespace Tests\Feature;

use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\Role;
use App\Models\User;
use App\Services\BookingWorkflowService;
use App\Services\FacilityScheduleBlockService;
use App\Services\GuestReservationManagementService;
use App\Services\ReservationFacilityOperationService;
use App\Services\RoomOccupantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class LiveFormValidationHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_reservation_detail_rejects_malformed_dates_before_rescheduling(): void
    {
        $this->actingAs($this->staff('Cashier'));

        Volt::test('cashier.reservations.index')
            ->set('detailActionId', 999)
            ->set('detailAction', 'reschedule')
            ->set('detailCheckInDate', 'not-a-date')
            ->set('detailCheckOutDate', 'tomorrow')
            ->call('saveDetailAction')
            ->assertHasErrors(['detailCheckInDate' => 'date_format', 'detailCheckOutDate' => 'date_format']);
    }

    public function test_cashier_occupant_forms_reject_empty_names_and_unbounded_lists(): void
    {
        $this->actingAs($this->staff('Cashier'));

        Volt::test('cashier.reservations.index')
            ->set('detailActionId', 999)
            ->set('detailAction', 'occupants')
            ->set('detailOccupants', [['first_name' => '', 'last_name' => 'Guest']])
            ->call('saveDetailAction')
            ->assertHasErrors('detailOccupants.0.first_name');

        Volt::test('cashier.bookings.index')
            ->set('occupantForm.booking_details_id', '999')
            ->set('occupantForm.occupants', array_fill(0, 51, ['first_name' => 'Guest', 'last_name' => 'Name']))
            ->call('saveOccupants')
            ->assertHasErrors(['occupantForm.occupants' => 'max']);
    }

    public function test_room_occupant_service_rejects_malformed_name_data(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Every room occupant needs valid name fields.');

        app(RoomOccupantService::class)->normalize(['not-an-occupant'], 1);
    }

    public function test_reschedule_services_reject_malformed_dates_before_parsing(): void
    {
        foreach ([
            fn () => app(ReservationFacilityOperationService::class)->rescheduleDetail(999, '2026-99-99', null, 999),
            fn () => app(BookingWorkflowService::class)->rescheduleBookingDetail(999, 'not-a-date'),
        ] as $reschedule) {
            try {
                $reschedule();
                $this->fail('Malformed date was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('valid', $exception->getMessage());
            }
        }
    }

    public function test_schedule_generation_rejects_invalid_and_unbounded_date_ranges(): void
    {
        $service = app(FacilityScheduleBlockService::class);

        $this->assertCount(1, $service->requiredBlocks(
            1,
            FacilitySchedulePolicy::Overnight,
            FacilityRateCode::Overnight,
            '2026-10-10 00:00:00',
            '2026-10-11 00:00:00',
        ));

        foreach ([
            ['2026-99-99', '2026-10-11'],
            ['2026-10-10', '2028-10-10'],
        ] as [$checkIn, $checkOut]) {
            try {
                $service->requiredBlocks(1, FacilitySchedulePolicy::Overnight, FacilityRateCode::Overnight, $checkIn, $checkOut);
                $this->fail('Invalid or excessive date range was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_admin_report_url_with_invalid_dates_opens_a_recoverable_form(): void
    {
        $this->actingAs($this->staff('Admin'))
            ->get(route('admin.reports.index', [
                'generated' => 1,
                'start' => 'invalid',
                'end' => '2026-10-10',
                'print_all' => 1,
            ]))
            ->assertOk()
            ->assertSee('Report filters are invalid. Review the fields and generate the report again.');
    }

    public function test_cashier_report_url_with_invalid_dates_opens_a_recoverable_form(): void
    {
        $this->actingAs($this->staff('Cashier'))
            ->get(route('cashier.reports.index', [
                'generated' => 1,
                'start' => '2026-99-99',
                'end' => '2026-10-10',
                'print_all' => 1,
            ]))
            ->assertOk()
            ->assertSee('Report filters are invalid. Review the fields and generate the report again.');
    }

    public function test_rejection_requires_a_reason_without_closing_the_dialog(): void
    {
        $this->actingAs($this->staff('Cashier'));

        Volt::test('cashier.gcash-verifications.index')
            ->set('selectedPaymentId', 999)
            ->set('showRejectDialog', true)
            ->set('rejectionReason', '')
            ->call('rejectSelected')
            ->assertHasErrors(['rejectionReason' => 'required'])
            ->assertSet('showRejectDialog', true);
    }

    public function test_stale_reservation_selection_is_a_recoverable_message(): void
    {
        $this->actingAs($this->staff('Cashier'));

        Volt::test('cashier.reservation-conversions.index')
            ->call('selectReservation', 999)
            ->assertSet('selectedReservationId', null)
            ->assertSee('This reservation is no longer available for conversion. Refresh the list.');
    }

    public function test_stale_booking_actions_keep_the_list_usable(): void
    {
        $this->actingAs($this->staff('Cashier'));

        foreach (['openReschedule', 'openTransfer', 'openCancelDetail', 'openOccupants'] as $action) {
            Volt::test('cashier.bookings.index')
                ->call($action, 999)
                ->assertHasNoErrors(['reschedule', 'transfer', 'cancelDetail', 'occupants'])
                ->assertSee('This booking facility is no longer available. Refresh the list and try again.');
        }

        Volt::test('cashier.bookings.index')
            ->call('extendBooking', 999)
            ->assertSee('The cottage could not be extended. Refresh the list and try again.');
    }

    public function test_booking_list_ignores_invalid_sort_direction_in_the_url(): void
    {
        $this->actingAs($this->staff('Cashier'))
            ->get(route('cashier.bookings.index', ['sort' => 'booking_id', 'direction' => 'sideways']))
            ->assertOk()
            ->assertSee('Booking Management');
    }

    public function test_stale_staff_account_actions_keep_the_list_usable(): void
    {
        $this->actingAs($this->staff('Admin'));

        Volt::test('admin.users.index')
            ->call('startEditingUser', 999)
            ->assertSet('showEditor', false)
            ->assertSee('This staff account is no longer available. Refresh the list and try again.');

        Volt::test('admin.users.index')
            ->call('requestStatusChange', 999)
            ->assertSet('showStatusDialog', false)
            ->assertSee('This staff account is no longer available. Refresh the list and try again.');

        Volt::test('admin.users.index')
            ->call('toggleStatus', 999)
            ->assertSee('This staff account is no longer available. Refresh the list and try again.');
    }

    public function test_stale_check_in_and_check_out_selections_are_recoverable(): void
    {
        $this->actingAs($this->staff('Cashier'));

        Volt::test('cashier.check-ins.index')
            ->call('selectCheckIn', 999)
            ->assertSet('selectedBookingDetailsId', null)
            ->assertSee('This check-in is no longer available. Refresh the list and try again.');

        Volt::test('cashier.check-outs.index')
            ->call('selectCheckOut', 999)
            ->assertSet('selectedBookingDetailsId', null)
            ->assertSee('This check-out is no longer available. Refresh the list and try again.');
    }

    public function test_check_in_and_check_out_lists_ignore_invalid_sort_directions(): void
    {
        $this->actingAs($this->staff('Cashier'));

        foreach (['cashier.check-ins.index', 'cashier.check-outs.index'] as $route) {
            $this->get(route($route, ['sort' => 'guest_name', 'direction' => 'sideways']))
                ->assertOk();
        }
    }

    public function test_stale_amenity_request_actions_keep_the_list_usable(): void
    {
        $this->actingAs($this->staff('Cashier'));

        Volt::test('cashier.amenity-requests.index')
            ->call('openEdit', 999)
            ->assertSet('editingRequestId', '')
            ->assertSee('This amenity request is no longer available. Refresh the list and try again.');

        Volt::test('cashier.amenity-requests.index')
            ->call('requestCancel', 999)
            ->assertSet('showCancelDialog', false)
            ->assertSee('This amenity request is no longer pending. Refresh the list and try again.');
    }

    public function test_guest_otp_verification_hides_unexpected_errors(): void
    {
        $service = \Mockery::mock(GuestReservationManagementService::class);
        $service->shouldReceive('verifyOtp')->once()->andThrow(new \RuntimeException('internal database error'));
        $this->app->instance(GuestReservationManagementService::class, $service);

        Volt::test('guest.reservations.manage')
            ->set('otp', '123456')
            ->call('verifyOtp')
            ->assertSet('errorMessage', 'We could not verify the code. Please try again or request a new one.')
            ->assertDontSee('internal database error');
    }

    private function staff(string $roleName): User
    {
        $role = Role::query()->create(['role_name' => $roleName]);

        return User::factory()->create(['role_id' => $role->role_id]);
    }
}

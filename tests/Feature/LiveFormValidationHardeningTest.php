<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\BookingWorkflowService;
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

    private function staff(string $roleName): User
    {
        $role = Role::query()->create(['role_name' => $roleName]);

        return User::factory()->create(['role_id' => $role->role_id]);
    }
}

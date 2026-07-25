<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\FacilityPrice;
use App\Models\FacilityType;
use App\Models\Reservation;
use App\Services\PublicReservationWorkflowService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PublicReservationJourneyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_reservation_redirects_to_a_dedicated_confirmation_page(): void
    {
        Mail::fake();

        [$facilityType, $facility] = $this->createReservableFacility();
        $checkInDate = now()->addDay()->toDateString();
        $checkOutDate = now()->addDays(2)->toDateString();

        $component = Livewire::test('guest.reservations.create')
            ->set('first_name', 'Maria')
            ->set('last_name', 'Santos')
            ->set('email', 'maria.santos@example.test')
            ->set('contact_no', '09171234567')
            ->set('province', 'South Cotabato')
            ->set('city', 'General Santos City')
            ->set(
                'facility_type_id',
                $facilityType->facility_type_id,
            )
            ->set('rate_type', 'Overnight')
            ->set('check_in_date', $checkInDate)
            ->set('check_out_date', $checkOutDate)
            ->set('facility_id', $facility->facility_id)
            ->set('total_guest_count', 2)
            ->call('save')
            ->assertHasNoErrors();

        $reservation = Reservation::query()
            ->with('guest')
            ->whereHas('guest', function ($query): void {
                $query->where(
                    'email',
                    'maria.santos@example.test',
                );
            })
            ->sole();

        $this->assertModelExists($reservation);

        $this->assertSame(
            $reservation->reservation_id,
            session('guest.reservation_confirmation_id'),
        );

        $component->assertRedirect(
            route('guest.reservations.success'),
        );

        $this->get(route('guest.reservations.success'))
            ->assertOk()
            ->assertSee('Thank you, Maria.')
            ->assertSee($reservation->r_ref_no)
            ->assertSee($facility->facility_name)
            ->assertSee('This is a reservation hold, not yet a confirmed booking.')
            ->assertSee('Manage this reservation');
    }

    public function test_confirmation_page_does_not_disclose_a_reservation_without_the_submission_session(): void
    {
        Mail::fake();

        [, $facility] = $this->createReservableFacility();

        $reservation = app(
            PublicReservationWorkflowService::class,
        )->createGuestReservation([
            'first_name' => 'Hidden',
            'middle_name' => null,
            'last_name' => 'Guest',
            'email' => 'hidden.guest@example.test',
            'contact_no' => '09170000000',
            'province' => 'South Cotabato',
            'city' => 'General Santos City',
            'barangay' => null,
            'purok' => null,
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'total_guest_count' => 2,
            'extra_guests' => [],
        ]);

        $this->get(route('guest.reservations.success'))
            ->assertOk()
            ->assertSee('No recent reservation is available in this browser.')
            ->assertDontSee($reservation->r_ref_no)
            ->assertDontSee('hidden.guest@example.test')
            ->assertDontSee($facility->facility_name);
    }

    public function test_facility_taken_during_submission_is_shown_as_a_form_error(): void
    {
        Mail::fake();

        [$facilityType, $facility] = $this->createReservableFacility();
        $checkInDate = now()->addDay()->toDateString();
        $checkOutDate = now()->addDays(2)->toDateString();

        app(
            PublicReservationWorkflowService::class,
        )->createGuestReservation([
            'first_name' => 'First',
            'middle_name' => null,
            'last_name' => 'Guest',
            'email' => 'first.guest@example.test',
            'contact_no' => '09170000001',
            'province' => 'South Cotabato',
            'city' => 'General Santos City',
            'barangay' => null,
            'purok' => null,
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'total_guest_count' => 2,
            'extra_guests' => [],
        ]);

        Livewire::test('guest.reservations.create')
            ->set('first_name', 'Second')
            ->set('last_name', 'Guest')
            ->set('email', 'second.guest@example.test')
            ->set('contact_no', '09170000002')
            ->set('province', 'South Cotabato')
            ->set('city', 'General Santos City')
            ->set(
                'facility_type_id',
                $facilityType->facility_type_id,
            )
            ->set('rate_type', 'Overnight')
            ->set('check_in_date', $checkInDate)
            ->set('check_out_date', $checkOutDate)
            ->set('facility_id', $facility->facility_id)
            ->set('total_guest_count', 2)
            ->call('save')
            ->assertHasErrors(['facility_id']);

        $this->assertSame(1, Reservation::query()->count());
        $this->assertNull(
            session('guest.reservation_confirmation_id'),
        );
    }

    private function createReservableFacility(): array
    {
        $facilityType = FacilityType::query()->create([
            'facility_type' => 'Room',
        ]);

        $facility = Facility::query()->create([
            'facility_name' => 'Spring Room 1',
            'facility_type_id' =>
                $facilityType->facility_type_id,
            'facility_size' => 'Standard',
            'facility_status' => 'Available',
            'capacity' => '4',
        ]);

        FacilityPrice::query()->create([
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Overnight',
            'facility_price' => 1500,
        ]);

        return [$facilityType, $facility];
    }
}

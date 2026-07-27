<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Facility;
use App\Models\FacilityPrice;
use App\Models\FacilityType;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Services\PublicBookingWorkflowService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PublicBookingJourneyTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_booking_redirects_to_a_dedicated_confirmation_page(): void
    {
        Mail::fake();
        Storage::fake('local');

        [$facilityType, $facility] = $this->createBookableFacility();
        ModeOfPayment::query()->firstOrCreate([
            'mode_of_payment' => 'GCash',
        ]);

        $checkInDate = now()->addDay()->toDateString();
        $checkOutDate = now()->addDays(2)->toDateString();

        $component = Livewire::test('guest.bookings.create')
            ->set('facility_type_id', $facilityType->facility_type_id)
            ->set('rate_type', 'Overnight')
            ->set('check_in_date', $checkInDate)
            ->set('check_out_date', $checkOutDate)
            ->set('check_in_time', '14:00')
            ->set('facility_id', $facility->facility_id)
            ->set('total_guest_count', 2)
            ->set('first_name', 'Maria')
            ->set('last_name', 'Santos')
            ->set('contact_no', '09171234567')
            ->set('email', 'maria.booking@example.test')
            ->set('province', 'South Cotabato')
            ->set('city', 'General Santos City')
            ->set('payment_amount', '1500.00')
            ->set('reference_number', '1234567890123')
            ->set(
                'proof_of_payment',
                UploadedFile::fake()->create(
                    'gcash-proof.pdf',
                    100,
                    'application/pdf',
                ),
            )
            ->call('save')
            ->assertHasNoErrors();

        $booking = Booking::query()
            ->with(['guest', 'details.facility', 'payments'])
            ->whereHas('guest', function ($query): void {
                $query->where(
                    'email',
                    'maria.booking@example.test',
                );
            })
            ->sole();

        $payment = $booking->payments->sole();

        $this->assertModelExists($booking);
        $this->assertSame('Pending Verification', $booking->status);
        $this->assertSame('Pending', $payment->payment_status);
        $this->assertSame(
            $booking->booking_id,
            session('guest.booking_confirmation_id'),
        );

        Storage::disk('local')->assertExists(
            $payment->proof_of_payment_path,
        );

        $component->assertRedirect(
            route('guest.bookings.success'),
        );

        $this->get(route('guest.bookings.success'))
            ->assertOk()
            ->assertSee('Thank you for your booking request.')
            ->assertSee($booking->b_ref_no)
            ->assertSee($facility->facility_name)
            ->assertSee('Ending in 0123')
            ->assertSee('pending cashier verification')
            ->assertDontSee('1234567890123')
            ->assertDontSee('confirmed booking');

        $this->get(route('guest.bookings.success'))
            ->assertOk()
            ->assertSee($booking->b_ref_no);
    }

    public function test_confirmation_page_does_not_disclose_a_booking_without_the_submission_session(): void
    {
        Mail::fake();

        [, $facility] = $this->createBookableFacility();
        ModeOfPayment::query()->firstOrCreate([
            'mode_of_payment' => 'GCash',
        ]);

        $booking = app(
            PublicBookingWorkflowService::class,
        )->createGuestBookingWithPendingGcash([
            'first_name' => 'Hidden',
            'middle_name' => null,
            'last_name' => 'Guest',
            'email' => 'hidden.booking@example.test',
            'contact_no' => '09170000000',
            'province' => 'South Cotabato',
            'city' => 'General Santos City',
            'barangay' => null,
            'purok' => null,
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'check_in_time' => '14:00',
            'total_guest_count' => 2,
            'extra_guests' => [],
            'payment_amount' => 1500.00,
            'reference_number' => 'GCASH-HIDDEN-001',
            'proof_of_payment_path' => 'gcash-proofs/hidden.pdf',
        ]);

        $this->get(route('guest.bookings.success'))
            ->assertOk()
            ->assertSee('No recent booking is available in this browser.')
            ->assertDontSee($booking->b_ref_no)
            ->assertDontSee('hidden.booking@example.test')
            ->assertDontSee('GCASH-HIDDEN-001')
            ->assertDontSee($facility->facility_name);
    }

    public function test_stale_confirmation_session_renders_the_safe_empty_state(): void
    {
        session()->put(
            'guest.booking_confirmation_id',
            999999,
        );

        $this->get(route('guest.bookings.success'))
            ->assertOk()
            ->assertSee('No recent booking is available in this browser.')
            ->assertSee('Reserve a Facility')
            ->assertSee('Find a confirmation');
    }

    public function test_failed_booking_cleans_up_the_uploaded_proof_and_does_not_create_a_confirmation_session(): void
    {
        Mail::fake();
        Storage::fake('local');

        [$facilityType, $facility] = $this->createBookableFacility();
        ModeOfPayment::query()->firstOrCreate([
            'mode_of_payment' => 'GCash',
        ]);

        $checkInDate = now()->addDay()->toDateString();
        $checkOutDate = now()->addDays(2)->toDateString();

        app(
            PublicBookingWorkflowService::class,
        )->createGuestBookingWithPendingGcash([
            'first_name' => 'Existing',
            'middle_name' => null,
            'last_name' => 'Guest',
            'email' => 'existing.booking@example.test',
            'contact_no' => '09170000001',
            'province' => 'South Cotabato',
            'city' => 'General Santos City',
            'barangay' => null,
            'purok' => null,
            'facility_id' => $facility->facility_id,
            'rate_type' => 'Overnight',
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'check_in_time' => '14:00',
            'total_guest_count' => 2,
            'extra_guests' => [],
            'payment_amount' => 1500.00,
            'reference_number' => '9876543210001',
            'proof_of_payment_path' =>
                'gcash-proofs/existing.pdf',
        ]);

        Livewire::test('guest.bookings.create')
            ->set('facility_type_id', $facilityType->facility_type_id)
            ->set('rate_type', 'Overnight')
            ->set('check_in_date', $checkInDate)
            ->set('check_out_date', $checkOutDate)
            ->set('check_in_time', '14:00')
            ->set('facility_id', $facility->facility_id)
            ->set('total_guest_count', 2)
            ->set('first_name', 'Second')
            ->set('last_name', 'Guest')
            ->set('contact_no', '09170000002')
            ->set('email', 'second.booking@example.test')
            ->set('province', 'South Cotabato')
            ->set('city', 'General Santos City')
            ->set('payment_amount', '1500.00')
            ->set('reference_number', '9876543210002')
            ->set(
                'proof_of_payment',
                UploadedFile::fake()->create(
                    'second-proof.pdf',
                    100,
                    'application/pdf',
                ),
            )
            ->call('save')
            ->assertSee('not available');

        $this->assertNull(
            session('guest.booking_confirmation_id'),
        );
        $this->assertSame(1, Booking::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles('gcash-proofs'),
        );
    }

    private function createBookableFacility(): array
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

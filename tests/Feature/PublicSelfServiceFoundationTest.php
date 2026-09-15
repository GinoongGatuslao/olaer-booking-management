<?php

namespace Tests\Feature;

use App\Models\Reservation;
use App\Services\GuestConfirmationLookupService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class PublicSelfServiceFoundationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_self_service_pages_use_the_olaer_visual_foundation(): void
    {
        $this->get(route('guest.confirmations.lookup'))
            ->assertOk()
            ->assertSee('Find your Olaer confirmation')
            ->assertSee('Your visit details, in one place')
            ->assertSee('Reserve a Facility');

        $this->get(route('guest.reservations.manage'))
            ->assertOk()
            ->assertSee('Manage your reservation securely')
            ->assertSee('Find your reservation')
            ->assertSee('Online changes have limits');
    }

    public function test_confirmation_lookup_requires_a_reference_and_matching_email(): void
    {
        Livewire::test('guest.confirmations.lookup')
            ->call('search')
            ->assertHasErrors([
                'reference_no',
                'email',
            ])
            ->set('reference_no', 'UNKNOWN-REFERENCE')
            ->set('email', 'guest@example.test')
            ->call('search')
            ->assertHasNoErrors()
            ->assertSet('searched', true)
            ->assertSee('We could not find that confirmation.');
    }

    public function test_confirmation_result_identifiers_cannot_be_changed_by_the_client(): void
    {
        $this->expectException(
            CannotUpdateLockedPropertyException::class,
        );

        Livewire::test('guest.confirmations.lookup')
            ->set('reservation_id', 999999);
    }

    public function test_successful_and_unsuccessful_confirmation_lookups_consume_attempts(): void
    {
        $reservation = new Reservation;
        $reservation->reservation_id = 12345;

        $lookup = $this->mock(GuestConfirmationLookupService::class);
        $lookup->shouldReceive('reservation')
            ->once()
            ->with('R-FOUND', 'Guest@Example.test')
            ->andReturn($reservation);
        $lookup->shouldReceive('reservation')
            ->once()
            ->with('R-MISSING', 'Guest@Example.test')
            ->andReturnNull();

        $successfulKey = $this->confirmationRateLimitKey(
            'reservation',
            'R-FOUND',
            'Guest@Example.test',
        );
        $unsuccessfulKey = $this->confirmationRateLimitKey(
            'reservation',
            'R-MISSING',
            'Guest@Example.test',
        );
        RateLimiter::clear($successfulKey);
        RateLimiter::clear($unsuccessfulKey);

        Livewire::test('guest.confirmations.lookup')
            ->set('reference_no', 'R-FOUND')
            ->set('email', 'Guest@Example.test')
            ->call('search')
            ->assertSet('reservation_id', 12345);

        Livewire::test('guest.confirmations.lookup')
            ->set('reference_no', 'R-MISSING')
            ->set('email', 'Guest@Example.test')
            ->call('search')
            ->assertSet('searched', true)
            ->assertSet('reservation_id', null);

        $this->assertSame(1, RateLimiter::attempts($successfulKey));
        $this->assertSame(1, RateLimiter::attempts($unsuccessfulKey));
    }

    public function test_confirmation_lookup_is_limited_to_ten_attempts_with_a_generic_message(): void
    {
        $reference = 'RATE-LIMITED-LOOKUP';
        $email = 'lookup-limit@example.test';
        RateLimiter::clear($this->confirmationRateLimitKey(
            'reservation',
            $reference,
            $email,
        ));

        $component = Livewire::test('guest.confirmations.lookup')
            ->set('reference_no', $reference)
            ->set('email', $email);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $component
                ->call('search')
                ->assertHasNoErrors('reference_no');
        }

        $component
            ->call('search')
            ->assertHasErrors('reference_no')
            ->assertSet('searched', false)
            ->assertSee(
                'Too many confirmation lookup attempts. Please try again later.',
            );
    }

    public function test_reservation_verification_state_cannot_be_changed_by_the_client(): void
    {
        $this->expectException(
            CannotUpdateLockedPropertyException::class,
        );

        Livewire::test('guest.reservations.manage')
            ->set('verified', true);
    }

    public function test_unverified_reservation_actions_are_rejected_server_side(): void
    {
        Livewire::test('guest.reservations.manage')
            ->call('prepareUpdate')
            ->assertSet('showUpdateForm', false)
            ->assertSet(
                'errorMessage',
                'Verify your reservation before making changes.',
            )
            ->call('prepareCancel')
            ->assertSet('showCancelForm', false)
            ->assertSet(
                'errorMessage',
                'Verify your reservation before making changes.',
            );
    }

    private function confirmationRateLimitKey(
        string $type,
        string $referenceNumber,
        string $email,
    ): string {
        $identity = implode('|', [
            '127.0.0.1',
            $type,
            strtoupper(trim($referenceNumber)),
            strtolower(trim($email)),
        ]);

        return 'confirmation-lookup:'.hash('sha256', $identity);
    }
}

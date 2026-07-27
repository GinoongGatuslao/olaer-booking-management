<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFineEditorDialogTest extends TestCase
{
    use RefreshDatabase;

    public function test_fine_and_damage_type_editors_open_from_list_and_close_independently(): void
    {
        Livewire::test('admin.fines.index')
            ->call('createNewFine')
            ->assertSet('showFineEditor', true)
            ->call('cancelFineEdit')
            ->assertSet('showFineEditor', false)
            ->call('manageDamageTypes')
            ->assertSet('showDamageTypes', true)
            ->call('closeDamageTypes')
            ->assertSet('showDamageTypes', false);
    }
}

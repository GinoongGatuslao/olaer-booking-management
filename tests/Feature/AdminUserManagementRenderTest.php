<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserManagementRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_management_component_renders(): void
    {
        Livewire::test('admin.users.index')
            ->assertSee('User list');
    }
}

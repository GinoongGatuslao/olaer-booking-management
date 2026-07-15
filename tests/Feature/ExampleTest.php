<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_home_returns_a_successful_response(): void
    {
        $response = $this->get(route('guest.home'));

        $response->assertOk();
    }
}

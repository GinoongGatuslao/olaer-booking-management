<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped(
            'Olaer staff profiles are managed by Admin/Manager through User Management; the starter-kit self-service profile and account-deletion workflow is outside capstone scope.',
        );
    }

    public function test_profile_page_is_displayed(): void
    {
        // Intentionally skipped in setUp().
    }

    public function test_profile_information_can_be_updated(): void
    {
        // Intentionally skipped in setUp().
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        // Intentionally skipped in setUp().
    }

    public function test_user_can_delete_their_account(): void
    {
        // Intentionally skipped in setUp().
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        // Intentionally skipped in setUp().
    }
}

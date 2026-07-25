<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserPasswordResetOtp;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login', absolute: false));

            return true;
        });
    }

    public function test_staff_password_reset_otp_remains_bound_to_the_requesting_account(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $component = Volt::test('auth.forgot-password')
            ->set('identifier', $user->email)
            ->call('requestCode')
            ->assertSet('step', 'verify');

        $otp = UserPasswordResetOtp::query()
            ->where('user_id', $user->user_id)
            ->latest('password_reset_otp_id')
            ->firstOrFail();

        $otp->update(['code_hash' => Hash::make('123456')]);

        $component
            ->set('identifier', 'changed@example.com')
            ->set('otp', ' 123456 ')
            ->call('verifyCode')
            ->assertHasNoErrors('otp')
            ->assertSet('step', 'reset');

        $this->assertNotNull($otp->refresh()->verified_at);
    }
}

<?php

use App\Services\StaffPasswordResetService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] #[Title('Forgot Password - Olaer Spring Resort')] class extends Component
{
    public string $step = 'request';

    public string $identifier = '';

    public string $otp = '';

    public string $resetToken = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $statusMessage = '';

    public function requestCode(StaffPasswordResetService $service): void
    {
        $this->validate([
            'identifier' => ['required', 'string', 'max:100'],
        ], [
            'identifier.required' => 'Enter your username or email address.',
        ]);

        $key = 'staff-password-reset:'.sha1(strtolower($this->identifier).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            $this->addError('identifier', "Too many reset requests. Try again in {$seconds} seconds.");

            return;
        }

        RateLimiter::hit($key, 300);

        try {
            $service->requestOtp($this->identifier);
        } catch (\RuntimeException $exception) {
            $this->addError('identifier', $exception->getMessage());

            return;
        }

        $this->step = 'verify';
        $this->statusMessage = 'If the account is active and has a valid email address, a six-digit reset code has been sent.';
        $this->resetValidation();
    }

    public function verifyCode(StaffPasswordResetService $service): void
    {
        $this->validate([
            'identifier' => ['required', 'string', 'max:100'],
            'otp' => ['required', 'digits:6'],
        ]);

        try {
            $this->resetToken = $service->verifyOtp($this->identifier, $this->otp);
        } catch (\RuntimeException $exception) {
            $this->addError('otp', $exception->getMessage());

            return;
        }

        $this->step = 'reset';
        $this->statusMessage = 'Code verified. Create a new password.';
        $this->resetValidation();
    }

    public function resetPassword(StaffPasswordResetService $service): void
    {
        $this->validate([
            'identifier' => ['required', 'string', 'max:100'],
            'resetToken' => ['required', 'string', 'size:64'],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
        ], [
            'password.confirmed' => 'The password confirmation does not match.',
        ]);

        try {
            $service->resetPassword($this->identifier, $this->resetToken, $this->password);
        } catch (\RuntimeException $exception) {
            $this->addError('password', $exception->getMessage());

            return;
        }

        $this->reset(['otp', 'resetToken', 'password', 'password_confirmation']);
        $this->step = 'done';
        $this->statusMessage = 'Your password has been changed. You may now sign in.';
        $this->resetValidation();
    }

    public function startOver(): void
    {
        $this->reset([
            'identifier',
            'otp',
            'resetToken',
            'password',
            'password_confirmation',
            'statusMessage',
        ]);
        $this->step = 'request';
        $this->resetValidation();
    }
};
?>

<div class="w-full max-w-md">
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mb-6">
            <h1 class="text-2xl font-bold tracking-tight">Reset password</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Use the username or email address assigned to your staff account.
            </p>
        </div>

        @if ($statusMessage !== '')
            <div class="mb-5 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
                {{ $statusMessage }}
            </div>
        @endif

        @if ($step === 'request')
            <form wire:submit="requestCode" class="space-y-5">
                <div>
                    <flux:input
                        wire:model="identifier"
                        label="Username or email"
                        autocomplete="username"
                        autofocus
                    />
                    @error('identifier')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="requestCode">Send reset code</span>
                    <span wire:loading wire:target="requestCode">Sending…</span>
                </flux:button>
            </form>
        @elseif ($step === 'verify')
            <form wire:submit="verifyCode" class="space-y-5">
                <div>
                    <flux:input
                        wire:model="otp"
                        label="Six-digit reset code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        maxlength="6"
                        autofocus
                    />
                    @error('otp')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                    Verify code
                </flux:button>

                <flux:button type="button" variant="subtle" class="w-full" wire:click="startOver">
                    Start over
                </flux:button>
            </form>
        @elseif ($step === 'reset')
            <form wire:submit="resetPassword" class="space-y-5">
                <div>
                    <flux:input
                        wire:model="password"
                        type="password"
                        label="New password"
                        autocomplete="new-password"
                        autofocus
                    />
                    @error('password')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-zinc-500">
                        Minimum 8 characters with uppercase, lowercase, number, and symbol.
                    </p>
                </div>

                <flux:input
                    wire:model="password_confirmation"
                    type="password"
                    label="Confirm new password"
                    autocomplete="new-password"
                />

                <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
                    Change password
                </flux:button>
            </form>
        @else
            <div class="space-y-4">
                <a
                    href="{{ route('login') }}"
                    wire:navigate
                    class="inline-flex w-full items-center justify-center rounded-lg bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                >
                    Return to login
                </a>
            </div>
        @endif

        @if ($step !== 'done')
            <div class="mt-6 border-t border-zinc-200 pt-4 text-center dark:border-zinc-800">
                <a href="{{ route('login') }}" wire:navigate class="text-sm font-medium text-zinc-700 hover:underline dark:text-zinc-300">
                    Back to login
                </a>
            </div>
        @endif
    </div>
</div>

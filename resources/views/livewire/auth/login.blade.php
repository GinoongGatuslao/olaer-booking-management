<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use function Livewire\Volt\{layout, mount, rules, state, title};

layout('layouts.guest');
title('Login - Olaer Spring Resort');

state([
    'username' => '',
    'password' => '',
    'remember' => false,
]);

mount(function () {
    // Pre-fill the username (and tick "Remember me") when it was remembered before.
    $remembered = (string) request()->cookie('remembered_username', '');

    if ($remembered !== '') {
        $this->username = $remembered;
        $this->remember = true;
    }
});

rules([
    'username' => ['required', 'string'],
    'password' => ['required', 'string'],
]);

$login = function () {
    $this->validate();

    $ok = Auth::attempt(
        [
            'username' => $this->username,
            'password' => $this->password,
        ],
        (bool) $this->remember,
    );

    if (!$ok) {
        throw ValidationException::withMessages([
            'username' => 'The username or password is incorrect.',
        ]);
    }

    session()->regenerate();

    $user = Auth::user()->loadMissing('role');

    if ($user->status !== 'Active') {
        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        throw ValidationException::withMessages([
            'username' => 'Your account is inactive. Please contact the administrator.',
        ]);
    }

    // Shown as a toast on the page the user lands on.
    session()->flash('welcome', 'Welcome, '.trim((string) ($user->first_name ?: $user->username)));

    if ($this->remember) {
        // Remember the username (never the password) on this browser for 30 days.
        Cookie::queue('remembered_username', $this->username, 60 * 24 * 30);
    } else {
        Cookie::queue(Cookie::forget('remembered_username'));
    }

    $routeName = match ($user->role?->role_name) {
        'Admin' => 'admin.dashboard',
        'Cashier' => 'cashier.dashboard',
        'Maintenance Staff' => 'maintenance.dashboard',
        'Security Guard' => 'security.entrance-slips.create',
        default => 'dashboard',
    };

    return redirect()->route($routeName);
};

?>

<div class="w-full max-w-md">
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mb-6 text-center">
            <img src="/images/olaer/logo.png"
                alt="Olaer Spring Resort Logo"
                class="mx-auto mb-4 aspect-square w-[clamp(4rem,18vw,8rem)] rounded-full object-cover"
            />
            <h1 class="text-2xl font-bold tracking-tight">Olaer Spring Resort</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Booking Management with Billing System</p>
        </div>

        <form wire:submit="login" novalidate class="space-y-5">
            {{-- Empty fields only turn the box red; real problems (wrong password, inactive account) still show text. --}}
            @php($usernameError = filled($username) ? $errors->first('username') : '')

            <flux:field class="relative">
                <flux:label>Username</flux:label>
                <flux:input wire:model="username" name="username" autocomplete="username" autofocus :invalid="$errors->has('username')" />
                @if ($usernameError !== '')
                    <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $usernameError }}</p>
                @endif
            </flux:field>

            <flux:field class="relative">
                <flux:label>Password</flux:label>
                <flux:input wire:model="password" name="password" type="password" autocomplete="current-password" viewable :invalid="$errors->has('password')" />
            </flux:field>

            <div class="flex items-center justify-between gap-4">
                <flux:checkbox wire:model="remember" label="Remember me" />

                <a href="{{ route('password.request') }}" wire:navigate
                    class="text-sm font-medium text-zinc-700 hover:underline dark:text-zinc-300">
                    Forgot password?
                </a>
            </div>

            <flux:button type="submit" variant="primary" class="w-full">
                Login
            </flux:button>
        </form>
    </div>
</div>
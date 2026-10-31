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
            <h1 class="text-2xl font-bold tracking-tight">Olaer Spring Resort</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Booking Management with Billing System</p>
        </div>

        <form wire:submit="login" class="space-y-5">
            <div>
                <flux:input wire:model="username" label="Username" autocomplete="username" autofocus />
                @error('username')
                @enderror
            </div>

            <div>
                <flux:input wire:model="password" type="password" label="Password" autocomplete="current-password" viewable/>
                @error('password')
                @enderror
            </div>
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
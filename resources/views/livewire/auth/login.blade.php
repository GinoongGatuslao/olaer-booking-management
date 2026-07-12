<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use function Livewire\Volt\{layout, rules, state, title};

layout('layouts.guest');
title('Login - Olaer Spring Resort');

state([
    'username' => '',
    'password' => '',
    'remember' => false,
]);

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

    request()->session()->regenerate();

    $user = Auth::user()->loadMissing('role');

    if ($user->status !== 'Active') {
        Auth::guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        throw ValidationException::withMessages([
            'username' => 'Your account is inactive. Please contact the administrator.',
        ]);
    }

    $routeName = match ($user->role?->role_name) {
        'Admin', 'Manager' => 'admin.dashboard',
        'Cashier' => 'cashier.dashboard',
        'Maintenance Staff' => 'maintenance.dashboard',
        'Security Guard' => 'security.dashboard',
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
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <flux:input wire:model="password" type="password" label="Password" autocomplete="current-password" />
                @error('password')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex justify-between">
                <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <input type="checkbox" wire:model="remember"
                        class="rounded border-zinc-300 text-zinc-900 shadow-sm focus:ring-zinc-500">
                    <span>Remember me</span>
                </label>
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

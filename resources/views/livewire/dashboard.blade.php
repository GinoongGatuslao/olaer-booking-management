<?php

use function Livewire\Volt\{layout, mount, title};

layout('layouts.app');
title('Dashboard - Olaer Spring Resort');

// /dashboard is where Laravel sends people who are already logged in (for example when the
// site is reopened on /login), so send each role to its own dashboard.
mount(function () {
    $route = match (auth()->user()?->role?->role_name) {
        'Admin' => 'admin.dashboard',
        'Cashier' => 'cashier.dashboard',
        'Maintenance Staff' => 'maintenance.dashboard',
        'Security Guard' => 'security.entrance-slips.create',
        default => null,
    };

    if ($route !== null) {
        return $this->redirectRoute($route, navigate: false);
    }
});

?>

<div class="space-y-6">
    <x-toast :message="session('welcome')" :seconds="4" fresh />
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Dashboard</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Your account is active, but no role-specific dashboard was matched.
        </p>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
        <p class="text-sm">
            Logged in as <strong>{{ auth()->user()->full_name }}</strong>
            with role <strong>{{ auth()->user()->role?->role_name ?? 'No role' }}</strong>.
        </p>
    </div>
</div>
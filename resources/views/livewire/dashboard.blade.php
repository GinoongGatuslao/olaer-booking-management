<?php

use function Livewire\Volt\{layout, title};

layout('layouts.app');
title('Dashboard - Olaer Spring Resort');

?>

<div class="space-y-6">
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

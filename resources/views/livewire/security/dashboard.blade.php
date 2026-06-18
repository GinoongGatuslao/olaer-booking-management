<?php

use App\Models\EntranceSlip;
use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title('Security Dashboard - Olaer Spring Resort');

$todaySlips = computed(fn () => EntranceSlip::whereDate('created_at', today())->count());
$unpaidSlips = computed(fn () => EntranceSlip::where('status', 'Unpaid')->count());
$paidSlips = computed(fn () => EntranceSlip::where('status', 'Paid')->count());
$totalTouristsToday = computed(fn () => EntranceSlip::whereDate('created_at', today())->sum('no_of_Tourist'));

?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Security Dashboard</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Initial counters for entrance slip operations.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Slips Created Today</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->todaySlips }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Unpaid Slips</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->unpaidSlips }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Paid Slips</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->paidSlips }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Tourists Today</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->totalTouristsToday }}</p>
        </div>
    </div>
</div>

<?php

use App\Models\AmenityRequest;
use App\Models\GuestFine;
use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title('Maintenance Dashboard - Olaer Spring Resort');

$pendingRequests = computed(fn () => AmenityRequest::where('amenity_request_status', 'Pending')->count());
$deliveringRequests = computed(fn () => AmenityRequest::where('amenity_request_status', 'Delivering')->count());
$deliveredRequests = computed(fn () => AmenityRequest::where('amenity_request_status', 'Delivered')->count());
$recordedFines = computed(fn () => GuestFine::count());

?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Maintenance Dashboard</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Initial counters for amenity requests and facility inspections.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Pending Requests</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->pendingRequests }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Delivering</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->deliveringRequests }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Delivered</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->deliveredRequests }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Recorded Fines</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->recordedFines }}</p>
        </div>
    </div>
</div>

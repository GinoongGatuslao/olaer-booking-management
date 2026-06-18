<?php

use App\Models\Amenity;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\User;
use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title('Admin Dashboard - Olaer Spring Resort');

$totalUsers = computed(fn () => User::count());
$totalFacilities = computed(fn () => Facility::count());
$totalAmenities = computed(fn () => Amenity::count());
$totalVerifiedPayments = computed(fn () => Payment::where('payment_status', 'Verified')->sum('amount_paid'));

?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Admin Dashboard</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Initial overview for master data and verified revenue.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Users</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->totalUsers }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Facilities</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->totalFacilities }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Amenities</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->totalAmenities }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Verified Payments</p>
            <p class="mt-2 text-2xl font-semibold">₱{{ number_format($this->totalVerifiedPayments, 2) }}</p>
        </div>
    </div>
</div>

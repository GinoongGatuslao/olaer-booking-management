<?php

use App\Models\Booking;
use App\Models\EntranceSlip;
use App\Models\Payment;
use App\Models\Reservation;
use function Livewire\Volt\{computed, layout, title};

layout('layouts.app');
title('Cashier Dashboard - Olaer Spring Resort');

$pendingEntranceSlips = computed(fn () => EntranceSlip::where('status', 'Unpaid')->count());
$activeReservations = computed(fn () => Reservation::where('status', 'Active')->count());
$activeBookings = computed(fn () => Booking::where('status', 'Active')->count());
$pendingPayments = computed(fn () => Payment::where('payment_status', 'Pending')->count());

?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">Cashier Dashboard</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Initial counters for daily cashier operations.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Unpaid Entrance Slips</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->pendingEntranceSlips }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Active Reservations</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->activeReservations }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Booked Facilities</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->activeBookings }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Pending GCash Payments</p>
            <p class="mt-2 text-2xl font-semibold">{{ $this->pendingPayments }}</p>
        </div>
    </div>
</div>

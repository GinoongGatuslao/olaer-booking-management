<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.public')] #[Title('Olaer Spring Resort')] class extends Component
{
    // Static public homepage. No database logic here.
};

?>

<section class="bg-white dark:bg-zinc-950">
    <div class="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:px-8 lg:py-24">
        <div class="flex flex-col justify-center">
            <p class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tacurong City, Sultan Kudarat</p>
            <h1 class="mt-3 text-4xl font-bold tracking-tight text-zinc-950 dark:text-white sm:text-5xl">
                Book your stay at Olaer Spring Resort.
            </h1>
            <p class="mt-5 max-w-xl text-base leading-7 text-zinc-600 dark:text-zinc-300">
                Reserve cottages, rooms, and function halls online. Choose your preferred date, rate type, and facility before visiting the resort.
            </p>
            <div class="mt-8 flex flex-wrap gap-3">
                <flux:button href="{{ route('guest.reservations.create') }}" variant="primary">Create reservation</flux:button>
                <flux:button href="{{ route('login') }}" variant="subtle">Staff login</flux:button>
            </div>
        </div>

        <div class="rounded-3xl border border-zinc-200 bg-zinc-50 p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-950">
                    <div class="text-3xl font-bold">132</div>
                    <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Cottages</div>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-950">
                    <div class="text-3xl font-bold">12</div>
                    <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Rooms</div>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-950">
                    <div class="text-3xl font-bold">2</div>
                    <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Function halls</div>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-zinc-950">
                    <div class="text-3xl font-bold">GCash</div>
                    <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">For later booking payment proof</div>
                </div>
            </div>

            <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 text-sm leading-6 text-zinc-600 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-300">
                <p class="font-medium text-zinc-900 dark:text-white">Reservation note</p>
                <p class="mt-1">A reservation is a temporary facility hold. Final booking/payment verification is handled by the cashier.</p>
            </div>
        </div>
    </div>
</section>

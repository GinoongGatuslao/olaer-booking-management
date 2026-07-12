<?php

use App\Services\RealtimeDashboardService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Cashier Dashboard')] class extends Component {
    public function with(): array
    {
        return [
            'metrics' => app(RealtimeDashboardService::class)->cashier(),
        ];
    }
};
?>

<div class="space-y-6" wire:poll.10s>
    <div>
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Cashier Dashboard</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">Live operational counters and cashier shortcuts.</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-xl border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-sm text-zinc-500">Pending GCash</div>
            <div class="mt-2 text-2xl font-semibold">{{ $metrics['pending_gcash'] }}</div>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-sm text-zinc-500">Inspection Requests</div>
            <div class="mt-2 text-2xl font-semibold">{{ $metrics['pending_checkouts'] }}</div>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-sm text-zinc-500">Inspections Done Today</div>
            <div class="mt-2 text-2xl font-semibold">{{ $metrics['completed_inspections'] }}</div>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-sm text-zinc-500">Unpaid Bookings</div>
            <div class="mt-2 text-2xl font-semibold">{{ $metrics['unpaid_bookings'] }}</div>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-sm text-zinc-500">Today Revenue</div>
            <div class="mt-2 text-2xl font-semibold">₱{{ number_format($metrics['today_revenue'], 2) }}</div>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <a href="{{ route('cashier.action-center') }}" class="rounded-xl border bg-white p-5 shadow-sm hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">Action Center</a>
        <a href="{{ route('cashier.gcash-verifications.index') }}" class="rounded-xl border bg-white p-5 shadow-sm hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">GCash Verification</a>
        <a href="{{ route('cashier.check-outs.index') }}" class="rounded-xl border bg-white p-5 shadow-sm hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">Check-out</a>
        <a href="{{ route('cashier.payments.index') }}" class="rounded-xl border bg-white p-5 shadow-sm hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">Payments</a>
    </div>
</div>

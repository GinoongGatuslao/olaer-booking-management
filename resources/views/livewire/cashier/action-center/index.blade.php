<?php

use App\Services\OperationalAlertService;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Cashier Action Center - Olaer Spring Resort')] class extends Component {
    public array $alerts = [];

    public function mount(): void
    {
        $this->refreshActionCenter();
    }

    public function refreshActionCenter(): void
    {
        $this->alerts = app(OperationalAlertService::class)->cashierAlerts();
    }

    public function shortcutUrl(string $routeName): string
    {
        return Route::has($routeName) ? route($routeName) : '#';
    }
};

?>

<div wire:poll.10s.visible="refreshActionCenter" class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Cashier Action Center</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Live cashier shortcuts and alerts. This refreshes automatically every 10 seconds.
            </p>
        </div>

        <flux:button wire:click="refreshActionCenter" variant="primary">Refresh now</flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <a href="{{ $this->shortcutUrl('cashier.reservations.index') }}" class="rounded-xl border border-zinc-200 bg-white p-5 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800">
            <p class="text-sm text-zinc-500">Reservations</p>
            <p class="mt-2 font-semibold">Create / manage reservations</p>
        </a>

        <a href="{{ $this->shortcutUrl('cashier.reservation-conversions.index') }}" class="rounded-xl border border-zinc-200 bg-white p-5 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800">
            <p class="text-sm text-zinc-500">Conversion</p>
            <p class="mt-2 font-semibold">Convert reservation to booking</p>
        </a>

        <a href="{{ $this->shortcutUrl('cashier.bookings.index') }}" class="rounded-xl border border-zinc-200 bg-white p-5 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800">
            <p class="text-sm text-zinc-500">Bookings</p>
            <p class="mt-2 font-semibold">View / manage bookings</p>
        </a>

        <a href="{{ $this->shortcutUrl('cashier.payments.index') }}" class="rounded-xl border border-zinc-200 bg-white p-5 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800">
            <p class="text-sm text-zinc-500">Payments</p>
            <p class="mt-2 font-semibold">Record balances and fines</p>
        </a>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <a href="{{ $this->shortcutUrl('cashier.gcash-verifications.index') }}" class="rounded-xl border border-zinc-200 bg-white p-5 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800">
            <p class="text-sm text-zinc-500">GCash</p>
            <p class="mt-2 font-semibold">Verify online payments</p>
        </a>

        <a href="{{ $this->shortcutUrl('cashier.check-ins.index') }}" class="rounded-xl border border-zinc-200 bg-white p-5 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800">
            <p class="text-sm text-zinc-500">Check-in</p>
            <p class="mt-2 font-semibold">Admit fully-paid guests</p>
        </a>

        <a href="{{ $this->shortcutUrl('cashier.check-outs.index') }}" class="rounded-xl border border-zinc-200 bg-white p-5 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800">
            <p class="text-sm text-zinc-500">Check-out</p>
            <p class="mt-2 font-semibold">Confirm inspected guests</p>
        </a>

        <a href="{{ $this->shortcutUrl('cashier.billings.index') }}" class="rounded-xl border border-zinc-200 bg-white p-5 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800">
            <p class="text-sm text-zinc-500">Billing</p>
            <p class="mt-2 font-semibold">View billing statements</p>
        </a>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
            <div>
                <h2 class="font-semibold">Live cashier alerts</h2>
                <p class="text-sm text-zinc-500">{{ count($alerts) }} active alert/s</p>
            </div>
            <a href="{{ $this->shortcutUrl('cashier.notifications.index') }}" class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">Full notifications</a>
        </div>

        @if (count($alerts) === 0)
            <div class="p-8 text-center text-sm text-zinc-500">No cashier action needed right now.</div>
        @else
            <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
                @foreach ($alerts as $alert)
                    @php
                        $severity = $alert['severity'] ?? 'info';
                        $badgeClass = match ($severity) {
                            'danger' => 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
                            'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
                            'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
                            default => 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
                        };
                        $routeName = $alert['route_name'] ?? null;
                        $url = $routeName && Route::has($routeName) ? route($routeName) : '#';
                    @endphp

                    <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $badgeClass }}">{{ ucfirst($severity) }}</span>
                                <span class="text-xs text-zinc-500">{{ $alert['time_label'] ?? '' }}</span>
                            </div>
                            <div>
                                <h3 class="font-semibold">{{ $alert['title'] ?? 'Alert' }}</h3>
                                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $alert['message'] ?? '' }}</p>
                            </div>
                        </div>
                        <a href="{{ $url }}" class="shrink-0 rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                            {{ $alert['action_label'] ?? 'Open' }}
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

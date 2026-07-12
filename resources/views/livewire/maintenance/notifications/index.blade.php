<?php

use App\Services\OperationalAlertService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Maintenance Notifications - Olaer Spring Resort')] class extends Component {
    public array $alerts = [];

    public function mount(): void
    {
        $this->refreshAlerts();
    }

    public function refreshAlerts(): void
    {
        $this->alerts = app(OperationalAlertService::class)->maintenanceAlerts();
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Maintenance Notifications</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Live alerts for pending amenity requests and checked-in facilities that still need inspection.
            </p>
        </div>

        <flux:button wire:click="refreshAlerts" variant="primary">Refresh alerts</flux:button>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Total alerts</p>
            <p class="mt-2 text-2xl font-semibold">{{ count($alerts) }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Amenity requests</p>
            <p class="mt-2 text-2xl font-semibold">
                {{ collect($alerts)->where('type', 'pending_amenity_request')->count() }}
            </p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            <p class="text-sm text-zinc-500">Inspections needed</p>
            <p class="mt-2 text-2xl font-semibold">
                {{ collect($alerts)->where('type', 'inspection_needed')->count() }}
            </p>
        </div>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
            <h2 class="font-semibold">Actionable alerts</h2>
        </div>

        @if (count($alerts) === 0)
            <div class="p-8 text-center text-sm text-zinc-500">
                No maintenance alerts right now.
            </div>
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
                        $url = $routeName && \Illuminate\Support\Facades\Route::has($routeName) ? route($routeName) : '#';
                    @endphp

                    <div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $badgeClass }}">
                                    {{ ucfirst($severity) }}
                                </span>
                                <span class="text-xs text-zinc-500">{{ $alert['time_label'] ?? '' }}</span>
                            </div>

                            <div>
                                <h3 class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $alert['title'] ?? 'Alert' }}</h3>
                                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $alert['message'] ?? '' }}</p>
                            </div>
                        </div>

                        <div class="shrink-0">
                            <a href="{{ $url }}" class="inline-flex rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800">
                                {{ $alert['action_label'] ?? 'Open' }}
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
        Pending amenity requests appear after payment is completed. Inspection alerts disappear once maintenance records the facility checklist.
    </div>
</div>

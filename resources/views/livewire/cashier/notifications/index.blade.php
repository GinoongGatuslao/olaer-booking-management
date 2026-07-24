<?php

use App\Services\OperationalAlertService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Cashier Notifications - Olaer Spring Resort')] class extends Component {
    public array $alerts = [];

    public function mount(): void
    {
        $this->refreshAlerts();
    }

    public function refreshAlerts(): void
    {
        $this->alerts = app(OperationalAlertService::class)->cashierAlerts();
    }
};

?>

<div wire:poll.15s.visible="refreshAlerts" class="space-y-6">
    <x-staff-page-header
        eyebrow="Front desk operations"
        title="Cashier notifications"
        description="Respond to pending GCash proofs, upcoming arrivals, cottage end-time reminders, and unpaid checkout balances."
    >
        <x-slot:actions>
            <flux:button
                wire:click="refreshAlerts"
                wire:loading.attr="disabled"
                wire:target="refreshAlerts"
                variant="primary"
            >
                <span wire:loading.remove wire:target="refreshAlerts">Refresh alerts</span>
                <span wire:loading wire:target="refreshAlerts">Refreshing…</span>
            </flux:button>
        </x-slot:actions>
    </x-staff-page-header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-dashboard-stat-card
            label="Total alerts"
            :value="count($alerts)"
            description="All current cashier tasks."
        />

        <x-dashboard-stat-card
            label="Critical"
            :value="collect($alerts)->where('severity', 'danger')->count()"
            description="Tasks currently blocking checkout."
            tone="danger"
        />

        <x-dashboard-stat-card
            label="Warnings"
            :value="collect($alerts)->where('severity', 'warning')->count()"
            description="Payment or rental tasks requiring attention."
            tone="warning"
        />

        <x-dashboard-stat-card
            label="Information"
            :value="collect($alerts)->where('severity', 'info')->count()"
            description="Upcoming operational work."
            tone="info"
        />
    </div>

    <section class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-brand-border pb-4 dark:border-zinc-700">
            <h2 class="text-lg font-semibold text-brand-text dark:text-white">Actionable alerts</h2>
            <p class="mt-1 text-sm text-brand-text-muted dark:text-zinc-400">
                Opening an alert takes you directly to the record requiring attention.
            </p>
        </div>

        @if (count($alerts) === 0)
            <x-dashboard-empty-state
                class="mt-5"
                title="No cashier alerts"
                description="The work queue is clear. New live tasks will appear here automatically."
            />
        @else
            <div class="mt-5 grid gap-3">
                @foreach ($alerts as $alert)
                    <x-operational-alert-card
                        :alert="$alert"
                        wire:key="cashier-notification-alert-{{ $loop->index }}"
                    />
                @endforeach
            </div>
        @endif
    </section>

    <div class="rounded-2xl border border-brand-border bg-brand-water p-4 text-sm leading-6 text-brand-text dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100">
        These alerts are calculated from live records. They are not permanent notification history yet, so fixing the underlying task automatically removes the alert.
    </div>
</div>

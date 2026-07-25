<?php

use App\Services\CheckOutInspectionRequestService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Maintenance Action Center - Olaer Spring Resort')] class extends Component {
    public function with(): array
    {
        return [
            'inspectionRequests' => app(CheckOutInspectionRequestService::class)->pendingRequestsForMaintenance(),
        ];
    }
};
?>

<div class="space-y-6" wire:poll.10s.visible>
    <x-staff-page-header
        eyebrow="Maintenance operations"
        title="Maintenance action center"
        description="Prioritize cashier-sent checkout inspections and open the maintenance workspaces used throughout the shift."
    >
        <x-slot:actions>
            @if (Route::has('maintenance.facility-inspections.index'))
                <flux:button
                    href="{{ route('maintenance.facility-inspections.index') }}"
                    wire:navigate
                    variant="primary"
                >
                    Open inspections
                </flux:button>
            @endif
        </x-slot:actions>
    </x-staff-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @if (Route::has('maintenance.amenity-requests.index'))
            <x-staff-shortcut-card
                eyebrow="Guest service"
                title="Amenity requests"
                description="Accept pending requests and complete deliveries assigned to you."
                :href="route('maintenance.amenity-requests.index')"
                tone="warning"
            />
        @endif

        @if (Route::has('maintenance.facility-inspections.index'))
            <x-staff-shortcut-card
                eyebrow="Checkout"
                title="Facility inspections"
                description="Accept requests, record findings, and complete assigned inspections."
                :href="route('maintenance.facility-inspections.index')"
                tone="info"
            />
        @endif

        @if (Route::has('maintenance.notifications.index'))
            <x-staff-shortcut-card
                eyebrow="Live queue"
                title="Notifications"
                description="Review all current delivery and inspection alerts."
                :href="route('maintenance.notifications.index')"
                tone="secondary"
            />
        @endif
    </div>

    <section class="rounded-2xl border border-brand-border bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-2 border-b border-brand-border pb-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-700">
            <div>
                <h2 class="text-lg font-semibold text-brand-text dark:text-white">Pending facility inspections</h2>
                <p class="mt-1 text-sm text-brand-text-muted dark:text-zinc-400">
                    Only checkout inspection requests sent by Cashier appear here.
                </p>
            </div>

            <flux:badge
                :color="$inspectionRequests->isEmpty() ? 'green' : 'amber'"
                size="sm"
            >
                {{ $inspectionRequests->count() }} pending
            </flux:badge>
        </div>

        <div class="mt-5 grid gap-3">
            @forelse ($inspectionRequests as $request)
                <article
                    wire:key="maintenance-action-request-{{ $request->facility_inspection_request_id }}"
                    class="rounded-2xl border border-brand-border bg-brand-surface/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40"
                >
                    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-status-badge :status="$request->status" />
                                <span class="text-xs text-brand-text-muted dark:text-zinc-400">
                                    {{ $request->requested_at?->format('M d, Y h:i A') ?? 'Awaiting action' }}
                                </span>
                            </div>

                            <p class="mt-3 font-semibold text-brand-text dark:text-white">
                                {{ $request->booking?->b_ref_no }} — {{ $request->facility?->facility_name ?? 'No facility' }}
                            </p>

                            <p class="mt-1 text-sm text-brand-text-muted dark:text-zinc-300">
                                Guest: {{ $request->booking?->guest?->first_name }} {{ $request->booking?->guest?->last_name }}
                            </p>

                            <p class="mt-1 text-xs text-brand-text-muted dark:text-zinc-400">
                                Requested by {{ $request->requestedBy?->first_name }} {{ $request->requestedBy?->last_name }}
                            </p>
                        </div>

                        <flux:button
                            href="{{ route('maintenance.facility-inspections.index', ['request' => $request->facility_inspection_request_id]) }}"
                            wire:navigate
                            size="sm"
                            variant="ghost"
                            class="shrink-0"
                        >
                            Inspect
                        </flux:button>
                    </div>
                </article>
            @empty
                <x-dashboard-empty-state
                    title="No pending inspection requests"
                    description="The Cashier has not sent a checkout inspection request requiring acceptance."
                />
            @endforelse
        </div>
    </section>
</div>

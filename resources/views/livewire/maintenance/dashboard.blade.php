<?php

use App\Models\AmenityRequest;
use App\Models\GuestFine;
use App\Services\MaintenanceDashboardService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Maintenance Dashboard - Olaer Spring Resort')] class extends Component
{
    #[Computed]
    public function overview(): array
    {
        return app(MaintenanceDashboardService::class)->overview((int) auth()->id());
    }

    #[Computed]
    public function amenityWorkQueue()
    {
        return app(MaintenanceDashboardService::class)
            ->amenityWorkQueue((int) auth()->id());
    }

    #[Computed]
    public function inspectionWorkQueue()
    {
        return app(MaintenanceDashboardService::class)
            ->inspectionWorkQueue((int) auth()->id());
    }

    #[Computed]
    public function recentCompletedInspections()
    {
        return app(MaintenanceDashboardService::class)
            ->myRecentCompletedInspections((int) auth()->id());
    }

    #[Computed]
    public function recentReportedIssues()
    {
        return app(MaintenanceDashboardService::class)
            ->myRecentReportedIssues((int) auth()->id());
    }

    public function amenityItemSummary(AmenityRequest $request): string
    {
        return app(MaintenanceDashboardService::class)
            ->amenityItemSummary($request);
    }

    public function fineDescription(GuestFine $guestFine): string
    {
        return app(MaintenanceDashboardService::class)
            ->fineDescription($guestFine);
    }
};

?>

<div wire:poll.15s.visible class="space-y-6">
    <x-staff-page-header
        eyebrow="Maintenance operations"
        title="Service and inspection queue"
        description="Track amenity deliveries, cashier-requested inspections, and work completed under your account. Live data refreshes while this dashboard is visible."
    >
        <x-slot:actions>
            @if (Route::has('maintenance.action-center'))
                <flux:button
                    href="{{ route('maintenance.action-center') }}"
                    wire:navigate
                    variant="primary"
                >
                    Open Action Center
                </flux:button>
            @endif

            @if (Route::has('maintenance.amenity-requests.index'))
                <flux:button
                    href="{{ route('maintenance.amenity-requests.index') }}"
                    wire:navigate
                    variant="ghost"
                >
                    Amenity Requests
                </flux:button>
            @endif
        </x-slot:actions>
    </x-staff-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-dashboard-stat-card
            label="Pending amenity requests"
            :value="$this->overview['pending_amenity_requests']"
            description="Requests available for a maintenance staff member to accept."
            :href="Route::has('maintenance.amenity-requests.index') ? route('maintenance.amenity-requests.index') : null"
            action="Open delivery queue"
            tone="warning"
        />

        <x-dashboard-stat-card
            label="My active deliveries"
            :value="$this->overview['my_active_deliveries']"
            description="Requests you accepted but have not marked delivered."
            :href="Route::has('maintenance.amenity-requests.index') ? route('maintenance.amenity-requests.index', ['assignment' => 'mine']) : null"
            action="Continue deliveries"
            tone="info"
        />

        <x-dashboard-stat-card
            label="Delivered by me today"
            :value="$this->overview['my_deliveries_today']"
            description="Amenity deliveries completed under your account."
            tone="success"
        />

        <x-dashboard-stat-card
            label="Pending inspections"
            :value="$this->overview['pending_inspection_requests']"
            description="Checkout inspection requests sent by Cashier."
            :href="Route::has('maintenance.facility-inspections.index') ? route('maintenance.facility-inspections.index', ['status' => 'active']) : null"
            action="Review requests"
            tone="warning"
        />

        <x-dashboard-stat-card
            label="My inspections in progress"
            :value="$this->overview['my_inspections_in_progress']"
            description="Accepted inspections currently assigned to you."
            :href="Route::has('maintenance.facility-inspections.index') ? route('maintenance.facility-inspections.index', ['assignment' => 'mine']) : null"
            action="Continue inspections"
            tone="info"
        />

        <x-dashboard-stat-card
            label="Completed by me today"
            :value="$this->overview['my_completed_inspections_today']"
            description="Checkout inspections completed under your account."
            tone="success"
        />

        <x-dashboard-stat-card
            label="Issues reported today"
            :value="$this->overview['issues_reported_today']"
            description="Damage or fine records reported under your account."
            tone="danger"
        />

        <x-dashboard-stat-card
            label="Charges reported today"
            :value="'₱'.number_format($this->overview['charges_reported_today'], 2)"
            description="Total guest charges created from your reports."
            tone="secondary"
        />
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Amenity delivery queue</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Pending requests and deliveries currently assigned to you.
                    </p>
                </div>

                @if (Route::has('maintenance.amenity-requests.index'))
                    <flux:button
                        href="{{ route('maintenance.amenity-requests.index') }}"
                        wire:navigate
                        size="sm"
                        variant="ghost"
                    >
                        Manage Requests
                    </flux:button>
                @endif
            </div>

            <div class="space-y-3">
                @forelse ($this->amenityWorkQueue as $request)
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-medium">
                                    Request #{{ $request->amenity_request_id }}
                                    <span class="text-zinc-400">·</span>
                                    {{ $request->booking?->b_ref_no ?? 'No booking reference' }}
                                </p>

                                <p class="mt-1 text-sm text-zinc-500">
                                    {{ $request->booking?->guest?->full_name ?? 'Unknown guest' }}
                                </p>

                                <p class="mt-2 text-xs leading-5 text-zinc-500">
                                    {{ $this->amenityItemSummary($request) }}
                                </p>
                            </div>

                            <x-status-badge :status="$request->amenity_request_status" />
                        </div>
                    </div>
                @empty
                    <x-dashboard-empty-state
                        title="Amenity queue is clear"
                        description="New pending requests and deliveries assigned to you will appear here."
                    />
                @endforelse
            </div>
        </flux:card>

        <flux:card>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Inspection queue</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Cashier-sent requests, plus inspections currently assigned to you.
                    </p>
                </div>

                @if (Route::has('maintenance.action-center'))
                    <flux:button
                        href="{{ route('maintenance.action-center') }}"
                        wire:navigate
                        size="sm"
                        variant="ghost"
                    >
                        Open Action Center
                    </flux:button>
                @endif
            </div>

            <div class="space-y-3">
                @forelse ($this->inspectionWorkQueue as $request)
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ $request->facility?->facility_name ?? 'Unassigned facility' }}
                                    <span class="text-zinc-400">·</span>
                                    {{ $request->booking?->b_ref_no ?? 'No booking reference' }}
                                </p>

                                <p class="mt-1 text-sm text-zinc-500">
                                    {{ $request->booking?->guest?->full_name ?? 'Unknown guest' }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    Requested
                                    {{ $request->requested_at?->diffForHumans() ?? 'recently' }}
                                    @if ($request->requestedBy)
                                        by {{ $request->requestedBy->full_name }}
                                    @endif
                                </p>

                                @if ($request->request_notes)
                                    <p class="mt-2 text-xs text-zinc-500">
                                        Note: {{ $request->request_notes }}
                                    </p>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                <x-status-badge :status="$request->status" />

                                @if (Route::has('maintenance.facility-inspections.index'))
                                    <flux:button
                                        href="{{ route('maintenance.facility-inspections.index', ['request' => $request->facility_inspection_request_id]) }}"
                                        wire:navigate
                                        size="sm"
                                        variant="primary"
                                    >
                                        Inspect
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <x-dashboard-empty-state
                        title="Inspection queue is clear"
                        description="Cashier-sent requests and inspections assigned to you will appear here."
                    />
                @endforelse
            </div>
        </flux:card>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card>
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">My recent inspections</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Latest facility checklists completed using your account.
                    </p>
                </div>

                @if (Route::has('maintenance.facility-inspections.index'))
                    <flux:button
                        href="{{ route('maintenance.facility-inspections.index') }}"
                        wire:navigate
                        size="sm"
                        variant="ghost"
                    >
                        Inspection Module
                    </flux:button>
                @endif
            </div>

            <div class="space-y-3">
                @forelse ($this->recentCompletedInspections as $inspection)
                    <div class="flex items-start justify-between gap-4 border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ $inspection->facility?->facility_name ?? 'Unassigned facility' }}
                                · {{ $inspection->booking?->b_ref_no ?? 'No reference' }}
                            </p>

                            <p class="truncate text-xs text-zinc-500">
                                {{ $inspection->booking?->guest?->full_name ?? 'Unknown guest' }}
                            </p>

                            <p class="mt-1 text-xs text-zinc-500">
                                {{ $inspection->inspected_at?->format('M d, Y h:i A') ?? 'Unknown time' }}
                                · {{ $inspection->items->count() }} checklist item(s)
                            </p>
                        </div>

                        <x-status-badge :status="$inspection->inspection_status" />
                    </div>
                @empty
                    <x-dashboard-empty-state
                        title="No completed inspections yet"
                        description="Inspections you complete will appear here for quick reference."
                    />
                @endforelse
            </div>
        </flux:card>

        <flux:card>
            <div class="mb-4">
                <h2 class="text-lg font-semibold">My recently reported issues</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Damage, missing-item, and situational fines you recorded.
                </p>
            </div>

            <div class="space-y-3">
                @forelse ($this->recentReportedIssues as $guestFine)
                    <div class="flex items-start justify-between gap-4 border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ $this->fineDescription($guestFine) }}
                            </p>

                            <p class="truncate text-xs text-zinc-500">
                                {{ $guestFine->facility?->facility_name ?? 'Unknown facility' }}
                                · {{ $guestFine->booking?->b_ref_no ?? 'No booking reference' }}
                            </p>

                            <p class="mt-1 text-xs text-zinc-500">
                                {{ $guestFine->booking?->guest?->full_name ?? 'Unknown guest' }}
                                · Quantity {{ $guestFine->quantity }}
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-sm font-semibold">
                                ₱{{ number_format((float) $guestFine->total_charge, 2) }}
                            </p>
                            <p class="text-xs text-zinc-500">
                                {{ $guestFine->date_checked?->format('M d, Y') ?? 'Unknown date' }}
                            </p>
                        </div>
                    </div>
                @empty
                    <x-dashboard-empty-state
                        title="No issues reported yet"
                        description="Damage, missing-item, and situational fines you record will appear here."
                    />
                @endforelse
            </div>
        </flux:card>
    </div>
</div>

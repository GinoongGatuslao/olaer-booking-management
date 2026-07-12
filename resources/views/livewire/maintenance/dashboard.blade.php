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

<div wire:poll.15s class="space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Maintenance Dashboard</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Live maintenance work queues. Updates automatically every 15 seconds.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
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
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Pending amenity requests</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['pending_amenity_requests'] }}</p>
            <p class="mt-1 text-xs text-zinc-500">Paid requests waiting for a maintenance staff member.</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">My active deliveries</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['my_active_deliveries'] }}</p>
            <p class="mt-1 text-xs text-zinc-500">Requests you accepted but have not marked delivered.</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Delivered by me today</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['my_deliveries_today'] }}</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Pending inspection requests</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['pending_inspection_requests'] }}</p>
            <p class="mt-1 text-xs text-zinc-500">Only requests sent by the cashier appear here.</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">My inspections in progress</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['my_inspections_in_progress'] }}</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Completed by me today</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['my_completed_inspections_today'] }}</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Issues reported by me today</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['issues_reported_today'] }}</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Charges from my reports today</p>
            <p class="mt-2 text-3xl font-semibold">₱{{ number_format($this->overview['charges_reported_today'], 2) }}</p>
        </flux:card>
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

                            <flux:badge
                                color="{{ $request->amenity_request_status === 'Delivering' ? 'blue' : 'amber' }}"
                                size="sm"
                            >
                                {{ $request->amenity_request_status }}
                            </flux:badge>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">
                        No paid amenity requests currently need your attention.
                    </p>
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
                                <flux:badge
                                    color="{{ $request->status === 'In Progress' ? 'blue' : 'amber' }}"
                                    size="sm"
                                >
                                    {{ $request->status }}
                                </flux:badge>

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
                    <p class="text-sm text-zinc-500">
                        No cashier-sent inspection request currently needs your attention.
                    </p>
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

                        <flux:badge
                            color="{{ $inspection->inspection_status === 'Cleared' ? 'green' : 'red' }}"
                            size="sm"
                        >
                            {{ $inspection->inspection_status }}
                        </flux:badge>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">You have not completed an inspection yet.</p>
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
                    <p class="text-sm text-zinc-500">You have not reported a fine yet.</p>
                @endforelse
            </div>
        </flux:card>
    </div>
</div>

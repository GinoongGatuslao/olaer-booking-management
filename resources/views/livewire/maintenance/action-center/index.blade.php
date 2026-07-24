<?php

use App\Services\CheckOutInspectionRequestService;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        return [
            'inspectionRequests' => app(CheckOutInspectionRequestService::class)->pendingRequestsForMaintenance(),
        ];
    }
};
?>

<div class="space-y-6" wire:poll.10s.visible>
    <div>
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-zinc-100">Maintenance Action Center</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">Only cashier-sent inspection requests appear here.</p>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-950">
        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
            <h2 class="font-semibold text-zinc-900 dark:text-zinc-100">Pending Facility Inspections</h2>
        </div>

        <div class="divide-y divide-zinc-200 dark:divide-zinc-800">
            @forelse ($inspectionRequests as $request)
                <div class="flex flex-col gap-3 px-4 py-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $request->booking?->b_ref_no }} — {{ $request->facility?->facility_name ?? 'No facility' }}
                        </p>
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            Guest: {{ $request->booking?->guest?->first_name }} {{ $request->booking?->guest?->last_name }}
                        </p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-500">
                            Requested by {{ $request->requestedBy?->first_name }} {{ $request->requestedBy?->last_name }}
                            @if ($request->requested_at)
                                · {{ $request->requested_at->format('M d, Y h:i A') }}
                            @endif
                        </p>
                        <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">Status: {{ $request->status }}</p>
                    </div>

                    <a href="{{ route('maintenance.facility-inspections.index', ['request' => $request->facility_inspection_request_id]) }}"
                       class="inline-flex items-center justify-center rounded-lg border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-100 dark:hover:bg-zinc-900">
                        Inspect
                    </a>
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm text-zinc-500">
                    No pending inspection requests.
                </div>
            @endforelse
        </div>
    </div>
</div>

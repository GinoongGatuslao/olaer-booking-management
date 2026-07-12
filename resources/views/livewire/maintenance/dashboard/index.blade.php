<?php

use App\Services\RealtimeDashboardService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Maintenance Dashboard')] class extends Component {
    public function with(): array
    {
        return [
            'metrics' => app(RealtimeDashboardService::class)->maintenance(),
        ];
    }
};
?>

<div class="space-y-6" wire:poll.10s>
    <div>
        <h1 class="text-2xl font-semibold text-zinc-900 dark:text-white">Maintenance Dashboard</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400">Live maintenance tasks and shortcuts.</p>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-sm text-zinc-500">Inspection Requests</div>
            <div class="mt-2 text-2xl font-semibold">{{ $metrics['inspection_requests'] }}</div>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-sm text-zinc-500">Amenity Requests</div>
            <div class="mt-2 text-2xl font-semibold">{{ $metrics['amenity_requests'] }}</div>
        </div>
        <div class="rounded-xl border bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-sm text-zinc-500">Completed Today</div>
            <div class="mt-2 text-2xl font-semibold">{{ $metrics['completed_today'] }}</div>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        <a href="{{ route('maintenance.action-center') }}" class="rounded-xl border bg-white p-5 shadow-sm hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">Action Center</a>
        <a href="{{ route('maintenance.facility-inspections.index') }}" class="rounded-xl border bg-white p-5 shadow-sm hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">Facility Inspections</a>
        <a href="{{ route('maintenance.amenity-requests.index') }}" class="rounded-xl border bg-white p-5 shadow-sm hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800">Amenity Requests</a>
    </div>
</div>

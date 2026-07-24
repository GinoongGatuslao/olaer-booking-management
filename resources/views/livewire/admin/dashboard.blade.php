<?php

use App\Services\AdminDashboardService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Admin Dashboard - Olaer Spring Resort')] class extends Component
{
    #[Computed]
    public function overview(): array
    {
        return app(AdminDashboardService::class)->overview();
    }

    #[Computed]
    public function revenueTrend(): array
    {
        return app(AdminDashboardService::class)->revenueTrend();
    }

    #[Computed]
    public function facilitiesInUse()
    {
        return app(AdminDashboardService::class)->facilitiesInUse();
    }

    #[Computed]
    public function activeStaff()
    {
        return app(AdminDashboardService::class)->recentlyActiveStaff();
    }

    #[Computed]
    public function recentOperations()
    {
        return app(AdminDashboardService::class)->recentOperations();
    }
};

?>

<div wire:poll.15s.visible class="space-y-6">
    <x-staff-page-header
        eyebrow="Administration"
        title="Resort operations overview"
        description="Verified revenue, facility use, staff activity, and current configuration at a glance. Live data refreshes while this dashboard is visible."
    >
        <x-slot:actions>
            @if (Route::has('admin.reports.index'))
                <flux:button href="{{ route('admin.reports.index') }}" wire:navigate variant="primary">
                    Open Reports
                </flux:button>
            @endif

            @if (Route::has('admin.users.index'))
                <flux:button href="{{ route('admin.users.index') }}" wire:navigate variant="ghost">
                    Manage Users
                </flux:button>
            @endif
        </x-slot:actions>
    </x-staff-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <x-dashboard-stat-card
            label="Revenue today"
            :value="'₱'.number_format($this->overview['today_revenue'], 2)"
            description="Verified payments received today."
            tone="success"
        />

        <x-dashboard-stat-card
            label="Revenue this month"
            :value="'₱'.number_format($this->overview['month_revenue'], 2)"
            description="Verified payments in the current month."
            tone="secondary"
        />

        <x-dashboard-stat-card
            label="Facilities in use"
            :value="$this->overview['occupied_facilities']"
            description="Facilities tied to checked-in booking details."
            tone="info"
        />

        <x-dashboard-stat-card
            label="Pending GCash"
            :value="$this->overview['pending_gcash']"
            description="Guest-uploaded GCash proofs awaiting cashier review."
            tone="warning"
        />

        <x-dashboard-stat-card
            label="Recently active staff"
            :value="$this->overview['active_staff']"
            description="Authenticated activity within the last five minutes."
        />

        <x-dashboard-stat-card
            label="Configured amenities"
            :value="$this->overview['total_amenities']"
            description="Master-data count only; warehouse stock is outside this system."
            tone="secondary"
        />
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <flux:card class="xl:col-span-2">
            <div class="mb-5">
                <h2 class="text-lg font-semibold">Verified revenue — last 7 days</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Based only on verified payment records.</p>
            </div>

            @php
                $maximumRevenue = max(1, collect($this->revenueTrend)->max('total'));
            @endphp

            <div class="space-y-4">
                @foreach ($this->revenueTrend as $day)
                    @php
                        $percentage = ($day['total'] / $maximumRevenue) * 100;
                    @endphp

                    <div class="grid grid-cols-[3.5rem_1fr_auto] items-center gap-3">
                        <span class="text-sm text-zinc-500">{{ $day['label'] }}</span>
                        <div class="h-3 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div
                                class="h-full rounded-full bg-zinc-900 transition-all dark:bg-zinc-100"
                                style="width: {{ max(0, min(100, $percentage)) }}%"
                            ></div>
                        </div>
                        <span class="min-w-24 text-right text-sm font-medium">
                            ₱{{ number_format($day['total'], 2) }}
                        </span>
                    </div>
                @endforeach
            </div>
        </flux:card>

        <flux:card>
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Recently active staff</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Activity within the authenticated system.</p>
            </div>

            <div class="space-y-3">
                @forelse ($this->activeStaff as $user)
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ $user->full_name }}</p>
                            <p class="text-xs text-zinc-500">{{ $user->role?->role_name ?? 'No role' }}</p>
                        </div>

                        @php
                            $lastSeen = $user->last_seen_at ? \Illuminate\Support\Carbon::parse($user->last_seen_at) : null;
                            $isOnline = $lastSeen?->gte(now()->subMinutes(5));
                        @endphp

                        <flux:badge color="{{ $isOnline ? 'green' : 'zinc' }}" size="sm">
                            {{ $isOnline ? 'Active' : ($lastSeen?->diffForHumans() ?? 'Never') }}
                        </flux:badge>
                    </div>
                @empty
                    <x-dashboard-empty-state
                        title="No recent staff activity"
                        description="Authenticated activity will appear here as staff use the system."
                    />
                @endforelse
            </div>
        </flux:card>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card>
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Facilities currently in use</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Checked-in booking details.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[36rem] text-left text-sm">
                    <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-800">
                        <tr>
                            <th class="px-2 py-3">Facility</th>
                            <th class="px-2 py-3">Guest</th>
                            <th class="px-2 py-3">Check-out</th>
                            <th class="px-2 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($this->facilitiesInUse as $detail)
                            <tr>
                                <td class="px-2 py-3">
                                    <p class="font-medium">{{ $detail->facility?->facility_name ?? 'Unassigned' }}</p>
                                    <p class="text-xs text-zinc-500">{{ $detail->facility?->facilityType?->facility_type ?? 'Unknown type' }}</p>
                                </td>
                                <td class="px-2 py-3">{{ $detail->booking?->guest?->full_name ?? 'Unknown guest' }}</td>
                                <td class="px-2 py-3">{{ optional($detail->check_out_date)->format('d/m/Y') }}</td>
                                <td class="px-2 py-3"><x-status-badge status="Checked-in" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-2 py-8 text-center text-zinc-500">No facilities are currently checked in.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </flux:card>

        <flux:card>
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Recent operational activity</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Derived from actual transaction records.</p>
            </div>

            <div class="space-y-4">
                @forelse ($this->recentOperations as $activity)
                    <div class="border-b border-zinc-100 pb-4 last:border-0 last:pb-0 dark:border-zinc-800">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <flux:badge size="sm">{{ $activity['type'] }}</flux:badge>
                                    <p class="text-sm font-medium">{{ $activity['description'] }}</p>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $activity['actor'] }} · {{ $activity['role'] }}
                                </p>
                            </div>

                            <time class="shrink-0 text-right text-xs text-zinc-500">
                                {{ $activity['occurred_at']->format('d/m/Y') }}<br>
                                {{ $activity['occurred_at']->format('h:i A') }}
                            </time>
                        </div>
                    </div>
                @empty
                    <x-dashboard-empty-state
                        title="No recent operations"
                        description="New booking, payment, facility, and staff activity will appear here."
                    />
                @endforelse
            </div>
        </flux:card>
    </div>
</div>

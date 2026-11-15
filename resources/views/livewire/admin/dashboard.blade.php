<?php

use App\Services\AdminChartsService;
use App\Services\AdminDashboardService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Admin Dashboard - Olaer Spring Resort')] class extends Component
{
    #[Url(as: 'range', except: '30d')]
    public string $range = '30d';

    #[Computed]
    public function charts(): array
    {
        return app(AdminChartsService::class)->charts($this->range);
    }

    /** Column chart: bookings by status. */
    public function statusOptions(): array
    {
        $data = $this->charts['status'];

        return [
            'chart' => ['type' => 'bar'],
            'series' => [['name' => 'Bookings', 'data' => $data['values']]],
            'xaxis' => ['categories' => $data['labels']],
            'yaxis' => ['min' => 0, 'forceNiceScale' => true],
            'yFormats' => ['int'],
            'plotOptions' => ['bar' => ['columnWidth' => '45%', 'borderRadius' => 6, 'distributed' => true]],
            'colors' => ['#b7791f', '#247e79', '#174f3b'],
            'dataLabels' => ['enabled' => true],
            'legend' => ['show' => false],
            'noData' => ['text' => 'No bookings in this period'],
        ];
    }

    /** Bars + lines: total bookings, occupancy rate and average daily rate. */
    public function trendOptions(): array
    {
        $trend = $this->charts['trend'];

        return [
            'chart' => ['type' => 'line'],
            'series' => [
                ['name' => 'Total bookings', 'type' => 'column', 'data' => $trend['bookings']],
                ['name' => 'Occupancy rate', 'type' => 'line', 'data' => $trend['occupancy']],
                ['name' => 'Average daily rate (ADR)', 'type' => 'line', 'data' => $trend['adr']],
            ],
            'stroke' => ['width' => [0, 3, 3], 'curve' => 'smooth'],
            'xaxis' => ['categories' => $this->charts['labels'], 'tickAmount' => min(12, count($this->charts['labels']))],
            'yaxis' => [
                ['title' => ['text' => 'Bookings'], 'min' => 0, 'forceNiceScale' => true],
                ['opposite' => true, 'title' => ['text' => 'Occupancy'], 'min' => 0, 'max' => 100],
                ['opposite' => true, 'show' => false, 'min' => 0],
            ],
            'yFormats' => ['int', 'percent', 'peso'],
            'tooltipFormats' => ['int', 'percent', 'peso'],
            'plotOptions' => ['bar' => ['columnWidth' => '55%', 'borderRadius' => 4]],
            'colors' => ['#174f3b', '#b7791f', '#2c7a8b'],
            'markers' => ['size' => [0, 4, 4]],
            'legend' => ['position' => 'top'],
        ];
    }

    /** Stacked bars: verified revenue by category. */
    public function revenueOptions(): array
    {
        $series = [];

        foreach (AdminChartsService::REVENUE_CATEGORIES as $category) {
            $series[] = ['name' => $category, 'data' => $this->charts['revenue'][$category]];
        }

        return [
            'chart' => ['type' => 'bar', 'stacked' => true],
            'series' => $series,
            'xaxis' => ['categories' => $this->charts['labels'], 'tickAmount' => min(12, count($this->charts['labels']))],
            'yaxis' => ['min' => 0],
            'yFormats' => ['peso'],
            'tooltipFormats' => ['peso'],
            'plotOptions' => ['bar' => ['columnWidth' => '60%', 'borderRadius' => 2]],
            'colors' => ['#174f3b', '#2c7a8b', '#b7791f', '#247e79', '#b93b3b'],
            'dataLabels' => ['enabled' => false],
            'legend' => ['position' => 'top'],
        ];
    }

    /** Multi-line: bookings and reservations made on the website against by the cashier. */
    public function sourceOptions(): array
    {
        $series = [];

        foreach ($this->charts['sources'] as $name => $data) {
            $series[] = ['name' => $name, 'data' => $data];
        }

        return [
            'chart' => ['type' => 'line'],
            'series' => $series,
            'stroke' => ['width' => 3, 'curve' => 'smooth'],
            'xaxis' => ['categories' => $this->charts['labels'], 'tickAmount' => min(12, count($this->charts['labels']))],
            'yaxis' => ['min' => 0, 'forceNiceScale' => true],
            'yFormats' => ['int'],
            'tooltipFormats' => ['int'],
            'colors' => ['#174f3b', '#b7791f', '#2c7a8b', '#b93b3b'],
            'markers' => ['size' => 4],
            'legend' => ['position' => 'top'],
        ];
    }

    /** Heatmap: rooms and cottages by the day of the week a stay starts. */
    public function demandOptions(): array
    {
        $demand = $this->charts['demand'];
        $series = [];

        // Heatmap rows are drawn bottom to top, so Cottage is listed last to sit on top.
        foreach (['Room', 'Cottage'] as $type) {
            $series[] = [
                'name' => $type,
                'data' => array_map(
                    fn (string $day, int $count): array => ['x' => $day, 'y' => $count],
                    $demand['days'],
                    $demand[$type],
                ),
            ];
        }

        return [
            'chart' => ['type' => 'heatmap'],
            'series' => $series,
            'plotOptions' => ['heatmap' => ['radius' => 6, 'enableShades' => true, 'shadeIntensity' => 0.6]],
            'colors' => ['#174f3b'],
            'dataLabels' => ['enabled' => true],
            'legend' => ['show' => false],
        ];
    }

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
    <x-toast :message="session('welcome')" :seconds="4" fresh />
    <x-staff-page-header
        eyebrow="Administration"
        title="Resort operations overview"
        description="Verified revenue, facility use, staff activity, and current configuration at a glance. Live data refreshes while this dashboard is visible."
    >
        <x-slot:actions>

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
            description="Amount paid received today."
            tone="success"
        />

        <x-dashboard-stat-card
            label="Revenue this month"
            :value="'₱'.number_format($this->overview['month_revenue'], 2)"
            description="Amount paid in the current month."
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

    @assets
        <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.54.1/dist/apexcharts.min.js"></script>
    @endassets

    <div class="space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold">Performance analytics</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Bookings, occupancy, revenue and demand for the chosen period.</p>
            </div>

            <div class="w-full sm:w-64">
                <flux:select wire:model.live="range" aria-label="Period">
                    @foreach (\App\Services\AdminChartsService::RANGES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <flux:card>
                <div class="mb-3">
                    <h3 class="font-semibold">Booking status</h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Bookings made in the period, by where they stand now.</p>
                </div>
                <x-admin-chart id="status" :options="$this->statusOptions()" :height="300" />
            </flux:card>

            <flux:card class="xl:col-span-2">
                <div class="mb-3">
                    <h3 class="font-semibold">Bookings, occupancy rate and average daily rate</h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Bars: bookings made (cancelled left out). Lines: share of facilities occupied, and revenue per occupied facility-day.
                    </p>
                </div>
                <x-admin-chart id="trend" :options="$this->trendOptions()" :height="300" />
            </flux:card>

            <flux:card class="xl:col-span-2">
                <div class="mb-3">
                    <h3 class="font-semibold">Revenue by category</h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Verified payments by what they paid for. Booking payments are split into facility, amenity and fines; refunds reduce Reservation.
                    </p>
                </div>
                <x-admin-chart id="revenue" :options="$this->revenueOptions()" :height="320" />
            </flux:card>

            <flux:card>
                <div class="mb-3">
                    <h3 class="font-semibold">Room and cottage demand</h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Bookings and reservations by the day of the week the stay starts.</p>
                </div>
                <x-admin-chart id="demand" :options="$this->demandOptions()" :height="320" />
            </flux:card>

            <flux:card class="xl:col-span-3">
                <div class="mb-3">
                    <h3 class="font-semibold">Website and cashier bookings and reservations</h3>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">
                        Made by guests on the website against made by the cashier. Bookings converted from a reservation are counted once, as the reservation.
                    </p>
                </div>
                <x-admin-chart id="sources" :options="$this->sourceOptions()" :height="320" />
            </flux:card>
        </div>
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

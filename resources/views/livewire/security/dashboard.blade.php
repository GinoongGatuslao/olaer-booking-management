<?php

use App\Models\EntranceSlip;
use App\Services\SecurityDashboardService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Security Dashboard - Olaer Spring Resort')] class extends Component
{
    #[Computed]
    public function overview(): array
    {
        return app(SecurityDashboardService::class)
            ->overview((int) auth()->id());
    }

    #[Computed]
    public function admittedBreakdown(): array
    {
        return app(SecurityDashboardService::class)
            ->admittedGuestBreakdown();
    }

    #[Computed]
    public function recentSlips()
    {
        return app(SecurityDashboardService::class)
            ->myRecentSlips((int) auth()->id());
    }

    public function slipNumber(EntranceSlip $slip): string
    {
        return app(SecurityDashboardService::class)
            ->formatSlipNumber($slip);
    }

    public function createdTime(?string $time): string
    {
        return app(SecurityDashboardService::class)
            ->formatCreatedTime($time);
    }

    public function totalGuests(EntranceSlip $slip): int
    {
        return app(SecurityDashboardService::class)
            ->totalGuests($slip);
    }
};

?>

<div wire:poll.15s.visible class="space-y-6">
    <x-staff-page-header
        eyebrow="Entrance operations"
        title="Security entrance overview"
        description="Monitor today’s guest admissions and create accurate entrance slips before directing guests to Cashier for payment."
    >
        <x-slot:actions>
            @if (Route::has('security.entrance-slips.create'))
                <flux:button
                    href="{{ route('security.entrance-slips.create') }}"
                    wire:navigate
                    variant="primary"
                >
                    Create Entrance Slip
                </flux:button>
            @endif
        </x-slot:actions>
    </x-staff-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-dashboard-stat-card
            label="Slips I created today"
            :value="$this->overview['my_slips_today']"
            :description="'Resort total: '.$this->overview['resort_slips_today']"
        />

        <x-dashboard-stat-card
            label="Paid entrance slips"
            :value="$this->overview['paid_slips_today']"
            description="Paid slips count toward admitted guests."
            tone="success"
        />

        <x-dashboard-stat-card
            label="Unpaid entrance slips"
            :value="$this->overview['unpaid_slips_today']"
            :description="'Created by me: '.$this->overview['my_unpaid_slips_today']"
            tone="warning"
        />

        <x-dashboard-stat-card
            label="Admitted guests today"
            :value="$this->overview['admitted_guests_today']"
            :description="'Tourists included: '.$this->overview['tourists_today']"
            tone="info"
        />
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card>
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Paid guest-category breakdown</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Only paid slips are included because only paid guests are considered admitted.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="text-xs uppercase tracking-wide text-zinc-500">Adults</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $this->admittedBreakdown['adult'] }}</p>
                </div>

                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="text-xs uppercase tracking-wide text-zinc-500">Children</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $this->admittedBreakdown['children'] }}</p>
                </div>

                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="text-xs uppercase tracking-wide text-zinc-500">Senior / PWD</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $this->admittedBreakdown['pwd_sc'] }}</p>
                </div>

                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="text-xs uppercase tracking-wide text-zinc-500">Male</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $this->admittedBreakdown['male'] }}</p>
                </div>

                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="text-xs uppercase tracking-wide text-zinc-500">Female</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $this->admittedBreakdown['female'] }}</p>
                </div>

                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="text-xs uppercase tracking-wide text-zinc-500">Tourists</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $this->admittedBreakdown['tourist'] }}</p>
                </div>
            </div>
        </flux:card>

        <flux:card>
            <div class="mb-4">
                <h2 class="text-lg font-semibold">Operational reminder</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    The security guard creates the entrance slip. The cashier handles and verifies payment.
                </p>
            </div>

            <div class="space-y-3 text-sm">
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="font-medium">1. Record the correct headcount</p>
                    <p class="mt-1 text-zinc-500">
                        Adult + Children + Senior/PWD must equal Male + Female.
                    </p>
                </div>

                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="font-medium">2. Direct the guest to the cashier</p>
                    <p class="mt-1 text-zinc-500">
                        An unpaid slip does not yet represent an admitted guest.
                    </p>
                </div>

                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                    <p class="font-medium">3. Confirm payment status</p>
                    <p class="mt-1 text-zinc-500">
                        This dashboard changes the slip status automatically after cashier payment.
                    </p>
                </div>
            </div>
        </flux:card>
    </div>

    <flux:card>
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-semibold">My entrance slips today</h2>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    Recent slips created using your account.
                </p>
            </div>

            @if (Route::has('security.entrance-slips.create'))
                <flux:button
                    href="{{ route('security.entrance-slips.create') }}"
                    wire:navigate
                    size="sm"
                    variant="ghost"
                >
                    New Slip
                </flux:button>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[56rem] text-left text-sm">
                <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-800">
                    <tr>
                        <th class="px-2 py-3">Slip</th>
                        <th class="px-2 py-3">Time</th>
                        <th class="px-2 py-3">Guests</th>
                        <th class="px-2 py-3">Breakdown</th>
                        <th class="px-2 py-3">Total</th>
                        <th class="px-2 py-3">Status</th>
                        <th class="px-2 py-3">Handled by</th>
                        <th class="px-2 py-3 text-right">Action</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->recentSlips as $slip)
                        <tr wire:key="security-slip-{{ $slip->entrance_slip_id }}">
                            <td class="px-2 py-3 font-medium">
                                {{ $this->slipNumber($slip) }}
                            </td>

                            <td class="px-2 py-3">
                                {{ $this->createdTime($slip->time_created) }}
                            </td>

                            <td class="px-2 py-3 font-medium">
                                {{ $this->totalGuests($slip) }}
                            </td>

                            <td class="px-2 py-3 text-xs text-zinc-500">
                                A {{ $slip->no_of_adult }}
                                · C {{ $slip->no_of_children }}
                                · SC/PWD {{ $slip->no_of_PWD_SC }}
                                <br>
                                M {{ $slip->no_of_Male }}
                                · F {{ $slip->no_of_Female }}
                                · T {{ $slip->no_of_Tourist }}
                            </td>

                            <td class="px-2 py-3 font-medium">
                                ₱{{ number_format((float) $slip->total_price, 2) }}
                            </td>

                            <td class="px-2 py-3">
                                <x-status-badge :status="$slip->status" />
                            </td>

                            <td class="px-2 py-3 text-zinc-500">
                                {{ $slip->handledBy?->full_name ?? 'Waiting for cashier' }}
                            </td>

                            <td class="px-2 py-3 text-right">
                                @if (Route::has('print.entrance-slip'))
                                    <flux:button
                                        href="{{ route('print.entrance-slip', $slip) }}"
                                        target="_blank"
                                        size="sm"
                                        variant="ghost"
                                    >
                                        Print
                                    </flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-2 py-6">
                                <x-dashboard-empty-state
                                    title="No entrance slips created today"
                                    description="Create a slip when the next walk-in group arrives."
                                    :href="Route::has('security.entrance-slips.create') ? route('security.entrance-slips.create') : null"
                                    action="Create entrance slip"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>

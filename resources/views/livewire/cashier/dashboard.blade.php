<?php

use App\Services\CashierDashboardService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Cashier Dashboard - Olaer Spring Resort')] class extends Component
{
    #[Computed]
    public function overview(): array
    {
        return app(CashierDashboardService::class)->overview((int) auth()->id());
    }

    #[Computed]
    public function upcomingCheckIns()
    {
        return app(CashierDashboardService::class)->upcomingCheckIns();
    }

    #[Computed]
    public function checkoutQueue()
    {
        return app(CashierDashboardService::class)->checkoutQueue();
    }

    #[Computed]
    public function pendingGcashPayments()
    {
        return app(CashierDashboardService::class)->pendingGcashPayments();
    }

    #[Computed]
    public function recentPayments()
    {
        return app(CashierDashboardService::class)->recentPayments((int) auth()->id());
    }

    public function paymentTarget(\App\Models\Payment $payment): string
    {
        return app(CashierDashboardService::class)->paymentTarget($payment);
    }

    public function paymentGuest(\App\Models\Payment $payment): string
    {
        return app(CashierDashboardService::class)->paymentGuest($payment);
    }
};

?>

<div wire:poll.15s.visible class="space-y-6">
    <x-staff-page-header
        eyebrow="Front desk operations"
        title="Cashier work queue"
        description="Prioritize payment verification, arrivals, active stays, and checkout readiness. Live data refreshes while this dashboard is visible."
    >
        <x-slot:actions>
            @if (Route::has('cashier.action-center'))
                <flux:button href="{{ route('cashier.action-center') }}" wire:navigate variant="primary">
                    Open Action Center
                </flux:button>
            @endif

            @if (Route::has('cashier.payments.index'))
                <flux:button href="{{ route('cashier.payments.index') }}" wire:navigate variant="ghost">
                    Record Payment
                </flux:button>
            @endif
        </x-slot:actions>
    </x-staff-page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-dashboard-stat-card
            label="My verified revenue today"
            :value="'₱'.number_format($this->overview['my_revenue_today'], 2)"
            description="Payments recorded or verified by your account."
            tone="success"
        />

        <x-dashboard-stat-card
            label="Unpaid entrance slips"
            :value="$this->overview['unpaid_entrance_slips']"
            description="Walk-in slips waiting for cashier payment."
            :href="Route::has('cashier.entrance-slips.index') ? route('cashier.entrance-slips.index') : null"
            action="Process slips"
            tone="danger"
        />

        <x-dashboard-stat-card
            label="Pending GCash"
            :value="$this->overview['pending_gcash']"
            description="GCash proofs awaiting verification."
            :href="Route::has('cashier.gcash-verifications.index') ? route('cashier.gcash-verifications.index') : null"
            action="Review payments"
            tone="warning"
        />

        <x-dashboard-stat-card
            label="Active reservations"
            :value="$this->overview['active_reservations']"
            description="Reservations still available for payment or conversion."
            :href="Route::has('cashier.reservations.index') ? route('cashier.reservations.index') : null"
            action="View reservations"
            tone="secondary"
        />

        <x-dashboard-stat-card
            label="Check-ins today"
            :value="$this->overview['check_ins_today']"
            description="Fully paid facilities scheduled to arrive today."
            :href="Route::has('cashier.check-ins.index') ? route('cashier.check-ins.index') : null"
            action="Open check-in"
            tone="info"
        />

        <x-dashboard-stat-card
            label="Facilities checked in"
            :value="$this->overview['checked_in_facilities']"
            description="Facilities currently occupied by active stays."
        />

        <x-dashboard-stat-card
            label="Open inspections"
            :value="$this->overview['inspection_requests_open']"
            description="Checkout inspections pending or in progress."
            :href="Route::has('cashier.check-outs.index') ? route('cashier.check-outs.index') : null"
            action="View checkout queue"
            tone="warning"
        />

        <x-dashboard-stat-card
            label="Ready for checkout"
            :value="$this->overview['ready_for_checkout']"
            description="Inspection complete with the booking balance settled."
            :href="Route::has('cashier.check-outs.index') ? route('cashier.check-outs.index') : null"
            action="Complete checkout"
            tone="success"
        />
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Upcoming check-ins</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Fully paid facilities scheduled today or tomorrow.</p>
                </div>

                @if (Route::has('cashier.check-ins.index'))
                    <flux:button href="{{ route('cashier.check-ins.index') }}" wire:navigate size="sm" variant="ghost">
                        View All
                    </flux:button>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[38rem] text-left text-sm">
                    <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-800">
                        <tr>
                            <th class="px-2 py-3">Reference</th>
                            <th class="px-2 py-3">Guest</th>
                            <th class="px-2 py-3">Facility</th>
                            <th class="px-2 py-3">Schedule</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($this->upcomingCheckIns as $detail)
                            <tr>
                                <td class="px-2 py-3 font-medium">{{ $detail->booking?->b_ref_no ?? '—' }}</td>
                                <td class="px-2 py-3">{{ $detail->booking?->guest?->full_name ?? 'Unknown guest' }}</td>
                                <td class="px-2 py-3">
                                    <p class="font-medium">{{ $detail->facility?->facility_name ?? 'Unassigned' }}</p>
                                    <p class="text-xs text-zinc-500">{{ $detail->facility?->facilityType?->facility_type ?? 'Unknown type' }}</p>
                                </td>
                                <td class="px-2 py-3">
                                    {{ optional($detail->check_in_date)->format('d/m/Y') }}
                                    @if ($detail->check_in_time)
                                        <span class="block text-xs text-zinc-500">{{ \Illuminate\Support\Carbon::parse($detail->check_in_time)->format('h:i A') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-2 py-8 text-center text-zinc-500">
                                    No fully paid check-ins are scheduled today or tomorrow.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </flux:card>

        <flux:card>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Check-out work queue</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Status follows cashier request → maintenance inspection → payment → check-out.</p>
                </div>

                @if (Route::has('cashier.check-outs.index'))
                    <flux:button href="{{ route('cashier.check-outs.index') }}" wire:navigate size="sm" variant="ghost">
                        Open Check-out
                    </flux:button>
                @endif
            </div>

            <div class="space-y-3">
                @forelse ($this->checkoutQueue as $item)
                    @php($detail = $item['detail'])

                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ $detail->facility?->facility_name ?? 'Unassigned facility' }}
                                    <span class="text-zinc-400">·</span>
                                    {{ $detail->booking?->b_ref_no ?? 'No reference' }}
                                </p>
                                <p class="mt-1 text-sm text-zinc-500">
                                    {{ $detail->booking?->guest?->full_name ?? 'Unknown guest' }}
                                </p>
                                <p class="mt-1 text-xs text-zinc-500">
                                    Check-out: {{ optional($detail->check_out_date)->format('d/m/Y') ?? 'Not set' }}
                                    · Balance: ₱{{ number_format($item['amount_due'], 2) }}
                                </p>
                            </div>

                            <x-status-badge :status="$item['state']" />
                        </div>
                    </div>
                @empty
                    <x-dashboard-empty-state
                        title="Checkout queue is clear"
                        description="Checked-in facilities will appear here when checkout preparation is needed."
                    />
                @endforelse
            </div>
        </flux:card>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <flux:card>
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Pending GCash proofs</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Guest submissions waiting for cashier verification.</p>
                </div>

                @if (Route::has('cashier.gcash-verifications.index'))
                    <flux:button href="{{ route('cashier.gcash-verifications.index') }}" wire:navigate size="sm" variant="ghost">
                        Review
                    </flux:button>
                @endif
            </div>

            <div class="space-y-3">
                @forelse ($this->pendingGcashPayments as $payment)
                    <div class="flex items-center justify-between gap-4 border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ $payment->booking?->b_ref_no ?? $payment->reservation?->r_ref_no ?? $payment->p_ref_no }}
                            </p>
                            <p class="truncate text-xs text-zinc-500">
                                {{ $payment->booking?->guest?->full_name ?? $payment->reservation?->guest?->full_name ?? 'Guest' }}
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-sm font-semibold">₱{{ number_format((float) $payment->amount_paid, 2) }}</p>
                            <x-status-badge status="Pending" />
                        </div>
                    </div>
                @empty
                    <x-dashboard-empty-state
                        title="No GCash proof waiting"
                        description="New guest-uploaded GCash proofs will appear here for verification."
                    />
                @endforelse
            </div>
        </flux:card>

        <flux:card>
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">My recent payments</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400">Payments recorded or verified by your account.</p>
                </div>

                @if (Route::has('cashier.payments.index'))
                    <flux:button href="{{ route('cashier.payments.index') }}" wire:navigate size="sm" variant="ghost">
                        Payment History
                    </flux:button>
                @endif
            </div>

            <div class="space-y-3">
                @forelse ($this->recentPayments as $payment)
                    <div class="flex items-start justify-between gap-4 border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ $this->paymentTarget($payment) }}</p>
                            <p class="truncate text-xs text-zinc-500">
                                {{ $this->paymentGuest($payment) }}
                                · {{ $payment->modeOfPayment?->mode_of_payment ?? 'Unknown mode' }}
                            </p>
                            <p class="mt-1 text-xs text-zinc-500">{{ $payment->p_ref_no }}</p>
                        </div>

                        <div class="text-right">
                            <p class="text-sm font-semibold">₱{{ number_format((float) $payment->amount_paid, 2) }}</p>
                            <x-status-badge :status="$payment->payment_status" />
                        </div>
                    </div>
                @empty
                    <x-dashboard-empty-state
                        title="No recent cashier payments"
                        description="Payments you record or verify will appear here."
                    />
                @endforelse
            </div>
        </flux:card>
    </div>
</div>

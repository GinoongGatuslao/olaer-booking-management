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

<div wire:poll.15s class="space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Cashier Dashboard</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Live cashier work queues. Updates automatically every 15 seconds.
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
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
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">My verified revenue today</p>
            <p class="mt-2 text-3xl font-semibold">₱{{ number_format($this->overview['my_revenue_today'], 2) }}</p>
            <p class="mt-1 text-xs text-zinc-500">Payments recorded or verified by your account.</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Unpaid entrance slips</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['unpaid_entrance_slips'] }}</p>
            @if (Route::has('cashier.entrance-slips.index'))
                <a href="{{ route('cashier.entrance-slips.index') }}" wire:navigate class="mt-2 inline-block text-sm font-medium underline">
                    Process slips
                </a>
            @endif
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Pending GCash verification</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['pending_gcash'] }}</p>
            @if (Route::has('cashier.gcash-verifications.index'))
                <a href="{{ route('cashier.gcash-verifications.index') }}" wire:navigate class="mt-2 inline-block text-sm font-medium underline">
                    Review payments
                </a>
            @endif
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Active reservations</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['active_reservations'] }}</p>
            @if (Route::has('cashier.reservations.index'))
                <a href="{{ route('cashier.reservations.index') }}" wire:navigate class="mt-2 inline-block text-sm font-medium underline">
                    View reservations
                </a>
            @endif
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Scheduled check-ins today</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['check_ins_today'] }}</p>
            @if (Route::has('cashier.check-ins.index'))
                <a href="{{ route('cashier.check-ins.index') }}" wire:navigate class="mt-2 inline-block text-sm font-medium underline">
                    Open check-in
                </a>
            @endif
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Facilities currently checked in</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['checked_in_facilities'] }}</p>
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Open inspection requests</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['inspection_requests_open'] }}</p>
            @if (Route::has('cashier.check-outs.index'))
                <a href="{{ route('cashier.check-outs.index') }}" wire:navigate class="mt-2 inline-block text-sm font-medium underline">
                    View check-out queue
                </a>
            @endif
        </flux:card>

        <flux:card>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Ready for check-out</p>
            <p class="mt-2 text-3xl font-semibold">{{ $this->overview['ready_for_checkout'] }}</p>
            <p class="mt-1 text-xs text-zinc-500">Inspection complete and balance settled.</p>
        </flux:card>
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

                            <flux:badge color="{{ $item['badge_color'] }}" size="sm">
                                {{ $item['state'] }}
                            </flux:badge>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No checked-in facilities are waiting for check-out.</p>
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
                            <flux:badge color="amber" size="sm">Pending</flux:badge>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No GCash proof is waiting for verification.</p>
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
                            <flux:badge color="{{ $payment->payment_status === 'Verified' ? 'green' : ($payment->payment_status === 'Rejected' ? 'red' : 'amber') }}" size="sm">
                                {{ $payment->payment_status }}
                            </flux:badge>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">You have not recorded or verified any payments yet.</p>
                @endforelse
            </div>
        </flux:card>
    </div>
</div>

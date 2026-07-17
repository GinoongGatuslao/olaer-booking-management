<?php

use App\Models\Payment;
use App\Services\GcashPaymentVerificationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('GCash Verification - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'Pending';
    public ?int $selectedPaymentId = null;
    public string $rejectionReason = '';

    public function with(): array
    {
        return [
            'payments' => $this->payments(),
            'selectedPayment' => $this->selectedPayment(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->selectedPaymentId = null;
        $this->resetPage();
    }

    public function selectPayment(int $paymentId): void
    {
        $this->selectedPaymentId = $paymentId;
        $this->rejectionReason = '';
    }

    public function verifySelected(): void
    {
        if (! $this->selectedPaymentId) {
            session()->flash('error', 'Select a pending GCash payment first.');
            return;
        }

        try {
            app(GcashPaymentVerificationService::class)->verify(
                paymentId: $this->selectedPaymentId,
                cashierUserId: (int) Auth::id(),
            );

            session()->flash('success', 'GCash proof verified. Booking is now marked as Booked if fully paid.');
            $this->selectedPaymentId = null;
            $this->resetPage();
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());
        }
    }

    public function rejectSelected(): void
    {
        if (! $this->selectedPaymentId) {
            session()->flash('error', 'Select a pending GCash payment first.');
            return;
        }

        try {
            app(GcashPaymentVerificationService::class)->reject(
                paymentId: $this->selectedPaymentId,
                cashierUserId: (int) Auth::id(),
                reason: $this->rejectionReason,
            );

            session()->flash('success', 'GCash proof rejected. The selected facility is released from booking conflict checks.');
            $this->selectedPaymentId = null;
            $this->rejectionReason = '';
            $this->resetPage();
        } catch (Throwable $exception) {
            session()->flash('error', $exception->getMessage());
        }
    }

    public function payments(): LengthAwarePaginator
    {
        $query = Payment::query()
            ->with(['booking.guest', 'booking.details.facility', 'modeOfPayment', 'verifier'])
            ->whereNotNull('booking_id')
            ->whereNotNull('proof_of_payment_path')
            ->whereHas('modeOfPayment', function ($query): void {
                $query->whereRaw('LOWER(mode_of_payment) = ?', ['gcash']);
            });

        if ($this->statusFilter !== 'all') {
            $query->whereRaw('LOWER(payment_status) = ?', [strtolower($this->statusFilter)]);
        }

        if (filled($this->search)) {
            $term = '%' . trim($this->search) . '%';
            $query->where(function ($query) use ($term): void {
                $query->where('p_ref_no', 'like', $term)
                    ->orWhere('reference_number', 'like', $term)
                    ->orWhereHas('booking', function ($query) use ($term): void {
                        $query->where('b_ref_no', 'like', $term);
                    })
                    ->orWhereHas('booking.guest', function ($query) use ($term): void {
                        $query->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('contact_no', 'like', $term)
                            ->orWhere('email', 'like', $term);
                    });
            });
        }

        return $query
            ->latest('payment_id')
            ->paginate(10);
    }

    public function selectedPayment(): ?Payment
    {
        if (! $this->selectedPaymentId) {
            return null;
        }

        return Payment::query()
            ->with(['booking.guest.address', 'booking.details.facility.facilityType', 'booking.extraGuests', 'modeOfPayment', 'verifier'])
            ->find($this->selectedPaymentId);
    }

    public function proofUrl(?Payment $payment): ?string
    {
        if (
            ! $payment
            || blank($payment->proof_of_payment_path)
            || ! Route::has('payments.gcash-proof')
        ) {
            return null;
        }

        return route('payments.gcash-proof', $payment);
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-zinc-950 dark:text-white">GCash Payment Verification</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                Review guest-uploaded GCash proof before a guest booking becomes fully paid and ready for check-in.
            </p>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_430px]">
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px]">
                <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="Reference, guest, contact, email" />

                <div>
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Status</label>
                    <select wire:model.live="statusFilter" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950 dark:text-white">
                        <option value="Pending">Pending</option>
                        <option value="Verified">Verified</option>
                        <option value="Rejected">Rejected</option>
                        <option value="all">All</option>
                    </select>
                </div>
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-zinc-500">
                            <th class="px-3 py-3">Payment</th>
                            <th class="px-3 py-3">Booking</th>
                            <th class="px-3 py-3">Guest</th>
                            <th class="px-3 py-3">Amount</th>
                            <th class="px-3 py-3">Status</th>
                            <th class="px-3 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($payments as $payment)
                            <tr class="align-top hover:bg-zinc-50 dark:hover:bg-zinc-950/60">
                                <td class="px-3 py-3">
                                    <div class="font-medium text-zinc-950 dark:text-white">{{ $payment->p_ref_no }}</div>
                                    <div class="text-xs text-zinc-500">GCash ref: {{ $payment->reference_number }}</div>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="font-medium text-zinc-950 dark:text-white">{{ optional($payment->booking)->b_ref_no }}</div>
                                    <div class="text-xs text-zinc-500">{{ optional($payment->booking?->details->first()?->facility)->facility_name }}</div>
                                </td>
                                <td class="px-3 py-3">
                                    <div>{{ optional($payment->booking?->guest)->first_name }} {{ optional($payment->booking?->guest)->last_name }}</div>
                                    <div class="text-xs text-zinc-500">{{ optional($payment->booking?->guest)->contact_no }}</div>
                                </td>
                                <td class="px-3 py-3 font-medium">₱{{ number_format((float) $payment->amount_paid, 2) }}</td>
                                <td class="px-3 py-3">
                                    <span class="rounded-full bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                        {{ $payment->payment_status }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-right">
                                    <flux:button size="sm" variant="subtle" wire:click="selectPayment({{ $payment->payment_id }})">Review</flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-8 text-center text-zinc-500">No GCash proof records found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $payments->links() }}
            </div>
        </section>

        <aside class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <h2 class="text-lg font-semibold text-zinc-950 dark:text-white">Review details</h2>

            @if ($selectedPayment)
                @php($booking = $selectedPayment->booking)
                @php($proofUrl = $this->proofUrl($selectedPayment))

                <div class="mt-4 space-y-3 text-sm">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-zinc-500">Booking reference</p>
                        <p class="font-medium text-zinc-950 dark:text-white">{{ $booking?->b_ref_no }}</p>
                    </div>

                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900/60 dark:bg-emerald-950/30">
                        <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                            GCash reference number
                        </p>
                        <p class="mt-1 font-semibold tracking-wide text-emerald-950 dark:text-emerald-100">
                            {{ $selectedPayment->reference_number ?: 'No reference provided' }}
                        </p>
                        <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">
                            Match this reference and amount with the uploaded proof before reviewing.
                        </p>
                    </div>

                    <div>
                        <p class="text-xs uppercase tracking-wide text-zinc-500">Guest</p>
                        <p class="font-medium text-zinc-950 dark:text-white">{{ $booking?->guest?->first_name }} {{ $booking?->guest?->last_name }}</p>
                        <p class="text-zinc-500">{{ $booking?->guest?->email }} · {{ $booking?->guest?->contact_no }}</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-950">
                        <div>
                            <p class="text-zinc-500">Payment amount</p>
                            <p class="font-semibold">₱{{ number_format((float) $selectedPayment->amount_paid, 2) }}</p>
                        </div>
                        <div>
                            <p class="text-zinc-500">Booking amount due</p>
                            <p class="font-semibold">₱{{ number_format((float) ($booking?->amount_due ?? 0), 2) }}</p>
                        </div>
                        <div>
                            <p class="text-zinc-500">Payment status</p>
                            <p class="font-semibold">{{ $selectedPayment->payment_status }}</p>
                        </div>
                        <div>
                            <p class="text-zinc-500">Booking status</p>
                            <p class="font-semibold">{{ $booking?->status }}</p>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs uppercase tracking-wide text-zinc-500">Facility</p>
                        @foreach (($booking?->details ?? collect()) as $detail)
                            <div class="mt-2 rounded-xl border border-zinc-200 p-3 dark:border-zinc-800">
                                <p class="font-medium text-zinc-950 dark:text-white">{{ $detail->facility?->facility_name }}</p>
                                <p class="text-zinc-500">{{ $detail->rate_type }} · {{ optional($detail->check_in_date)->format('M d, Y') }} to {{ optional($detail->check_out_date)->format('M d, Y') }}</p>
                                <p class="text-zinc-500">Status: {{ $detail->status }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if ($proofUrl)
                        <div>
                            <p class="text-xs uppercase tracking-wide text-zinc-500">Proof of payment</p>
                            <a href="{{ $proofUrl }}" target="_blank" class="mt-1 inline-flex text-sm font-medium text-emerald-700 hover:underline dark:text-emerald-400">
                                Open uploaded proof
                            </a>
                        </div>
                    @endif

                    @if (strtolower((string) $selectedPayment->payment_status) === 'pending')
                        <div class="border-t border-zinc-200 pt-4 dark:border-zinc-800">
                            <flux:button variant="primary" class="w-full" wire:click="verifySelected" wire:confirm="Verify this GCash proof and mark booking as paid?">
                                Verify payment
                            </flux:button>

                            <div class="mt-4">
                                <flux:textarea wire:model="rejectionReason" label="Rejection reason" placeholder="Example: Amount does not match booking total or proof is unreadable." />
                                <flux:button variant="danger" class="mt-3 w-full" wire:click="rejectSelected" wire:confirm="Reject this GCash proof?">
                                    Reject payment
                                </flux:button>
                            </div>
                        </div>
                    @else
                        <div class="rounded-xl bg-zinc-50 p-3 text-sm text-zinc-600 dark:bg-zinc-950 dark:text-zinc-300">
                            Reviewed by: {{ $selectedPayment->verifier?->first_name }} {{ $selectedPayment->verifier?->last_name }}<br>
                            Reviewed at: {{ optional($selectedPayment->verified_at)->format('M d, Y h:i A') ?? '—' }}
                        </div>

                        @if (
                            strtolower((string) $selectedPayment->payment_status) === 'rejected'
                            && filled($selectedPayment->rejection_reason)
                        )
                            <div class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/30 dark:text-red-200">
                                <p class="text-xs font-semibold uppercase tracking-wide">
                                    Rejection reason
                                </p>
                                <p class="mt-1 whitespace-pre-line">
                                    {{ $selectedPayment->rejection_reason }}
                                </p>
                            </div>
                        @endif
                    @endif
                </div>
            @else
                <p class="mt-4 text-sm text-zinc-500">Select a GCash payment from the table to review its uploaded proof.</p>
            @endif
        </aside>
    </div>
</div>

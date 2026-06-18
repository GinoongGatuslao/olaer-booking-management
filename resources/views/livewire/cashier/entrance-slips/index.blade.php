<?php

use App\Models\EntranceSlip;
use App\Models\EntranceSlipDetail;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app'), Title('Entrance Slip Payments - Olaer Spring Resort')] class extends Component
{
    public string $search = '';

    public string $statusFilter = 'Unpaid';

    public string $sortField = 'entrance_slip_id';

    public string $sortDirection = 'desc';

    public ?int $selectedSlipId = null;

    public string $modeOfPaymentId = '';

    public string $referenceNumber = '';

    #[Computed]
    public function slips(): Collection
    {
        $search = trim($this->search);
        $sortDirection = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $query = EntranceSlip::query()
            ->with(['createdBy', 'handledBy', 'details.entranceFee', 'details.discount', 'payments.modeOfPayment'])
            ->when($this->statusFilter !== '', function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $numeric = preg_replace('/\D+/', '', $search);

                $query->where(function ($query) use ($like, $numeric) {
                    $query->where('status', 'like', $like)
                        ->orWhere('date_created', 'like', $like)
                        ->orWhere('time_created', 'like', $like)
                        ->orWhere('total_price', 'like', $like)
                        ->orWhere('amount_due', 'like', $like)
                        ->orWhereHas('createdBy', function ($query) use ($like) {
                            $query->where('first_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like)
                                ->orWhere('username', 'like', $like);
                        });

                    if ($numeric !== '') {
                        $query->orWhere('entrance_slip_id', (int) $numeric);
                    }
                });
            });

        return match ($this->sortField) {
            'date_created' => $query->orderBy('date_created', $sortDirection)->orderBy('time_created', $sortDirection)->get(),
            'total_guests' => $query->get()->sortBy(function (EntranceSlip $slip) {
                return $slip->no_of_adult + $slip->no_of_children + $slip->no_of_PWD_SC;
            }, SORT_REGULAR, $sortDirection === 'desc')->values(),
            'amount_due' => $query->orderBy('amount_due', $sortDirection)->get(),
            'status' => $query->orderBy('status', $sortDirection)->get(),
            default => $query->orderBy('entrance_slip_id', $sortDirection)->get(),
        };
    }

    #[Computed]
    public function paymentModes(): EloquentCollection
    {
        return ModeOfPayment::query()->orderBy('mode_of_payment')->get();
    }

    #[Computed]
    public function selectedSlip(): ?EntranceSlip
    {
        if (! $this->selectedSlipId) {
            return null;
        }

        return EntranceSlip::query()
            ->with(['createdBy', 'handledBy', 'details.entranceFee', 'details.discount', 'payments.modeOfPayment', 'payments.user'])
            ->find($this->selectedSlipId);
    }

    public function selectSlip(int $slipId): void
    {
        $slip = EntranceSlip::query()->findOrFail($slipId);

        $this->selectedSlipId = $slip->entrance_slip_id;
        $this->modeOfPaymentId = ModeOfPayment::query()->where('mode_of_payment', 'Cash')->value('mode_of_payment_id') ?: '';
        $this->referenceNumber = '';
        $this->resetValidation();
    }

    public function clearSelection(): void
    {
        $this->selectedSlipId = null;
        $this->modeOfPaymentId = '';
        $this->referenceNumber = '';
        $this->resetValidation();
    }

    public function sortBy(string $field): void
    {
        $allowedSorts = ['entrance_slip_id', 'date_created', 'total_guests', 'amount_due', 'status'];

        if (! in_array($field, $allowedSorts, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortField = $field;
        $this->sortDirection = $field === 'entrance_slip_id' ? 'desc' : 'asc';
    }

    public function recordPayment(): void
    {
        $validated = $this->validate([
            'selectedSlipId' => ['required', 'integer', 'exists:tbl_entrance_slip,entrance_slip_id'],
            'modeOfPaymentId' => ['required', 'integer', 'exists:tbl_mode_of_payment,mode_of_payment_id'],
            'referenceNumber' => ['nullable', 'string', 'max:50'],
        ], [
            'selectedSlipId.required' => 'Select an entrance slip first.',
            'modeOfPaymentId.required' => 'Mode of payment is required.',
        ]);

        $mode = ModeOfPayment::query()->findOrFail((int) $validated['modeOfPaymentId']);
        $referenceNumber = trim((string) ($validated['referenceNumber'] ?? ''));

        if ($mode->mode_of_payment === 'GCash' && $referenceNumber === '') {
            $this->addError('referenceNumber', 'GCash payments must include a reference number.');

            return;
        }

        try {
            DB::transaction(function () use ($validated, $mode, $referenceNumber): void {
                /** @var EntranceSlip $slip */
                $slip = EntranceSlip::query()
                    ->lockForUpdate()
                    ->findOrFail((int) $validated['selectedSlipId']);

                if ($slip->status === 'Paid' || (float) $slip->amount_due <= 0) {
                    throw new RuntimeException('This entrance slip is already paid.');
                }

                $amount = round((float) $slip->amount_due, 2);

                Payment::query()->create([
                    'p_ref_no' => $this->makePaymentReference(),
                    'entrance_slip_id' => $slip->entrance_slip_id,
                    'mode_of_payment_id' => $mode->mode_of_payment_id,
                    'reference_number' => $referenceNumber !== '' ? $referenceNumber : null,
                    'amount_paid' => $amount,
                    'date_paid' => now()->toDateString(),
                    'user_id' => auth()->id(),
                    'payment_status' => 'Verified',
                    'verified_by_user_id' => auth()->id(),
                    'verified_at' => now(),
                ]);

                $slip->update([
                    'amount_due' => 0,
                    'handled_by_user_id' => auth()->id(),
                    'status' => 'Paid',
                ]);
            });
        } catch (Throwable $exception) {
            $this->addError('selectedSlipId', $exception->getMessage());

            return;
        }

        session()->flash('success', 'Entrance slip payment recorded successfully.');
        $this->clearSelection();
    }

    public function makePaymentReference(): string
    {
        do {
            $reference = 'PAY-'.now()->format('Ymd').'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (Payment::query()->where('p_ref_no', $reference)->exists());

        return $reference;
    }

    public function formatSlipNo(?int $id): string
    {
        if (! $id) {
            return 'N/A';
        }

        return 'ES-'.now()->format('Ymd').'-'.str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public function formatMoney(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }

    public function formatPercent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 2), '0'), '.').'%';
    }

    public function detailSubtotal(EntranceSlipDetail $detail): float
    {
        return (float) $detail->entranceFee?->entrance_fee_price * (int) $detail->guest_quantity;
    }

    public function detailDiscountAmount(EntranceSlipDetail $detail): float
    {
        return (float) $detail->entranceFee?->entrance_fee_price
            * min((int) $detail->discounted_quantity, (int) $detail->guest_quantity)
            * (float) $detail->discount?->discount_amount;
    }

    public function detailTotal(EntranceSlipDetail $detail): float
    {
        return $this->detailSubtotal($detail) - $this->detailDiscountAmount($detail);
    }

    public function selectedSlipSubtotal(): float
    {
        return (float) $this->selectedSlip?->details->sum(fn (EntranceSlipDetail $detail): float => $this->detailSubtotal($detail));
    }

    public function selectedSlipDiscountTotal(): float
    {
        return (float) $this->selectedSlip?->details->sum(fn (EntranceSlipDetail $detail): float => $this->detailDiscountAmount($detail));
    }

    public function selectedSlipTotalAfterDiscounts(): float
    {
        return $this->selectedSlipSubtotal() - $this->selectedSlipDiscountTotal();
    }

    public function getSortIcon(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function getStatusBadgeClass(string $status): string
    {
        return match ($status) {
            'Paid' => 'bg-green-50 text-green-700 ring-1 ring-green-600/20 dark:bg-green-950/40 dark:text-green-300',
            'Unpaid' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-300',
            default => 'bg-zinc-100 text-zinc-700 ring-1 ring-zinc-600/10 dark:bg-zinc-800 dark:text-zinc-300',
        };
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Entrance Slip Payments</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                View slips created by security guards and record full entrance fee payment.
            </p>
        </div>

        <a href="{{ route('cashier.dashboard') }}" class="text-sm font-medium text-zinc-600 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white">
            Back to dashboard
        </a>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="xl:col-span-2">
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <h2 class="font-semibold">Entrance slip list</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Select an unpaid slip to process payment.
                            </p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 lg:w-[34rem]">
                            <flux:input
                                wire:model.live.debounce.300ms="search"
                                label="Search"
                                placeholder="Slip no., date, status, guard"
                                clearable
                            />

                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Status</label>
                                <select wire:model.live="statusFilter" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                                    <option value="">All slips</option>
                                    <option value="Unpaid">Unpaid</option>
                                    <option value="Paid">Paid</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-950/60 dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3 font-semibold"><button type="button" wire:click="sortBy('entrance_slip_id')">Slip {{ $this->getSortIcon('entrance_slip_id') }}</button></th>
                                <th class="px-5 py-3 font-semibold"><button type="button" wire:click="sortBy('date_created')">Created {{ $this->getSortIcon('date_created') }}</button></th>
                                <th class="px-5 py-3 font-semibold"><button type="button" wire:click="sortBy('total_guests')">Guests {{ $this->getSortIcon('total_guests') }}</button></th>
                                <th class="px-5 py-3 font-semibold"><button type="button" wire:click="sortBy('amount_due')">Due {{ $this->getSortIcon('amount_due') }}</button></th>
                                <th class="px-5 py-3 font-semibold"><button type="button" wire:click="sortBy('status')">Status {{ $this->getSortIcon('status') }}</button></th>
                                <th class="px-5 py-3 text-right font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->slips as $slip)
                                <tr wire:key="slip-{{ $slip->entrance_slip_id }}">
                                    <td class="px-5 py-4 font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $this->formatSlipNo($slip->entrance_slip_id) }}
                                        <div class="text-xs font-normal text-zinc-500 dark:text-zinc-400">Created by {{ $slip->createdBy?->full_name }}</div>
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $slip->date_created?->format('M d, Y') }}
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $slip->time_created }}</div>
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $slip->no_of_adult + $slip->no_of_children + $slip->no_of_PWD_SC }}
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            A: {{ $slip->no_of_adult }}, C: {{ $slip->no_of_children }}, SC/PWD: {{ $slip->no_of_PWD_SC }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 font-medium text-zinc-900 dark:text-zinc-100">₱{{ number_format((float) $slip->amount_due, 2) }}</td>
                                    <td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $this->getStatusBadgeClass($slip->status) }}">{{ $slip->status }}</span></td>
                                    <td class="px-5 py-4 text-right">
                                        <flux:button type="button" size="sm" variant="subtle" wire:click="selectSlip({{ $slip->entrance_slip_id }})">
                                            View
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">No entrance slips found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <aside>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="font-semibold">Payment panel</h2>

                @if ($this->selectedSlip)
                    <div class="mt-4 space-y-4">
                        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="font-medium">{{ $this->formatSlipNo($this->selectedSlip->entrance_slip_id) }}</div>
                                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->selectedSlip->date_created?->format('M d, Y') }} {{ $this->selectedSlip->time_created }}</div>
                                </div>
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $this->getStatusBadgeClass($this->selectedSlip->status) }}">{{ $this->selectedSlip->status }}</span>
                            </div>

                            <div class="mt-4 space-y-3 text-sm">
                                <div class="font-semibold text-zinc-900 dark:text-zinc-100">Breakdown</div>
                                @foreach ($this->selectedSlip->details as $detail)
                                    <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-950/50">
                                        <div class="flex justify-between gap-3">
                                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $detail->entranceFee?->entrance_fee_name }}</span>
                                            <span>{{ $this->formatMoney($this->detailSubtotal($detail)) }}</span>
                                        </div>
                                        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $detail->guest_quantity }} × {{ $this->formatMoney((float) $detail->entranceFee?->entrance_fee_price) }}
                                        </div>

                                        @if ($detail->discount && (int) $detail->discounted_quantity > 0)
                                            <div class="mt-2 flex justify-between gap-3 text-xs text-green-700 dark:text-green-300">
                                                <span>
                                                    {{ $detail->discounted_quantity }} discounted via {{ $detail->discount->discount_name }}
                                                    ({{ $this->formatPercent((float) $detail->discount->discount_amount) }})
                                                </span>
                                                <span>-{{ $this->formatMoney($this->detailDiscountAmount($detail)) }}</span>
                                            </div>
                                        @endif

                                        <div class="mt-2 flex justify-between gap-3 border-t border-zinc-200 pt-2 text-xs font-medium dark:border-zinc-800">
                                            <span>Line total</span>
                                            <span>{{ $this->formatMoney($this->detailTotal($detail)) }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="hidden">
                                @foreach ($this->selectedSlip->details as $detail)
                                    <div class="flex justify-between gap-3">
                                        <span>
                                            {{ $detail->entranceFee?->entrance_fee_name }} × {{ $detail->guest_quantity }}
                                            @if ($detail->discount)
                                                <span class="block text-xs text-zinc-500">{{ $detail->discounted_quantity }} discounted via {{ $detail->discount->discount_name }}</span>
                                            @endif
                                        </span>
                                        <span>₱{{ number_format((float) $detail->entranceFee?->entrance_fee_price, 2) }}</span>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-4 space-y-2 border-t border-zinc-200 pt-4 text-sm dark:border-zinc-800">
                                <div class="flex justify-between gap-3">
                                    <span>Subtotal before discounts</span>
                                    <span>{{ $this->formatMoney($this->selectedSlipSubtotal()) }}</span>
                                </div>
                                <div class="flex justify-between gap-3 text-green-700 dark:text-green-300">
                                    <span>Total discounts</span>
                                    <span>-{{ $this->formatMoney($this->selectedSlipDiscountTotal()) }}</span>
                                </div>
                                <div class="flex justify-between gap-3 font-semibold">
                                    <span>Total after discounts</span>
                                    <span>{{ $this->formatMoney($this->selectedSlipTotalAfterDiscounts()) }}</span>
                                </div>
                                <div class="flex justify-between gap-3 font-semibold">
                                    <span>Amount due</span>
                                    <span>{{ $this->formatMoney((float) $this->selectedSlip->amount_due) }}</span>
                                </div>
                            </div>

                            <div class="hidden">
                                <div class="flex justify-between font-semibold"><span>Total</span><span>₱{{ number_format((float) $this->selectedSlip->total_price, 2) }}</span></div>
                                <div class="flex justify-between font-semibold"><span>Amount due</span><span>₱{{ number_format((float) $this->selectedSlip->amount_due, 2) }}</span></div>
                            </div>
                        </div>

                        @if ($this->selectedSlip->status !== 'Paid')
                            <form wire:submit="recordPayment" class="space-y-4">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Mode of payment</label>
                                    <select wire:model.live="modeOfPaymentId" class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                                        <option value="">Select mode</option>
                                        @foreach ($this->paymentModes as $mode)
                                            <option value="{{ $mode->mode_of_payment_id }}">{{ $mode->mode_of_payment }}</option>
                                        @endforeach
                                    </select>
                                    @error('modeOfPaymentId') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                </div>

                                <flux:input wire:model="referenceNumber" label="GCash reference number" placeholder="Required for GCash only" />

                                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
                                    Entrance slips are paid in full. Partial payment is not allowed in this flow.
                                </div>

                                <div class="flex flex-col gap-3 sm:flex-row">
                                    <flux:button type="submit" variant="primary">Record payment</flux:button>
                                    <flux:button type="button" variant="subtle" wire:click="clearSelection">Cancel</flux:button>
                                </div>
                            </form>
                        @else
                            <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200">
                                This entrance slip is already paid.
                            </div>
                            <flux:button type="button" variant="subtle" wire:click="clearSelection">Close</flux:button>
                        @endif
                    </div>
                @else
                    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">Select a slip from the list to view details and process payment.</p>
                @endif
            </div>
        </aside>
    </div>
</div>

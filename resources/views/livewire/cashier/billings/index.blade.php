<?php

use App\Services\GuestTransactionHistoryService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Billing Statements - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'from', except: '')]
    public string $fromDate = '';

    #[Url(as: 'to', except: '')]
    public string $toDate = '';

    #[Url(as: 'payment_status', except: 'all')]
    public string $paymentStatus = 'all';

    #[Url(as: 'transaction_type', except: 'all')]
    public string $transactionType = 'all';

    #[Url(as: 'sort', except: 'date')]
    public string $sortField = 'date';

    #[Url(as: 'direction', except: 'desc')]
    public string $sortDirection = 'desc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?string $selectedTransactionType = null;
    public ?int $selectedTransactionId = null;
    public ?string $errorMessage = null;

    public function with(): array
    {
        $history = app(GuestTransactionHistoryService::class)->paginated(
            $this->filters(),
            $this->perPage,
            $this->sortField,
            $this->sortDirection,
        );

        return [
            'records' => $history,
            'statement' => $this->selectedStatement(),
        ];
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedFromDate(): void { $this->resetPage(); }
    public function updatedToDate(): void { $this->resetPage(); }
    public function updatedPaymentStatus(): void { $this->resetPage(); }
    public function updatedTransactionType(): void { $this->resetPage(); }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 10;
        }

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, [
            'date',
            'transaction_type',
            'guest_name',
            'amount',
            'amount_due',
            'payment_status',
        ], true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = $field === 'date' ? 'desc' : 'asc';
        }

        $this->resetPage();
    }

    public function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function selectTransaction(string $type, int $id): void
    {
        if (! in_array($type, ['reservation', 'booking'], true)) {
            return;
        }

        $this->selectedTransactionType = $type;
        $this->selectedTransactionId = $id;
        $this->errorMessage = null;
    }

    public function selectedStatement(): ?array
    {
        if ($this->selectedTransactionType === null || $this->selectedTransactionId === null) {
            return null;
        }

        try {
            return app(GuestTransactionHistoryService::class)->statement(
                $this->selectedTransactionType,
                $this->selectedTransactionId,
            );
        } catch (\Throwable $exception) {
            $this->errorMessage = $exception->getMessage();

            return null;
        }
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->fromDate = '';
        $this->toDate = '';
        $this->paymentStatus = 'all';
        $this->transactionType = 'all';
        $this->sortField = 'date';
        $this->sortDirection = 'desc';
        $this->perPage = 10;
        $this->resetPage();
    }

    public function filters(): array
    {
        return [
            'search' => $this->search,
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
            'payment_status' => $this->paymentStatus,
            'transaction_type' => $this->transactionType,
        ];
    }

    public function money(mixed $amount): string
    {
        return '₱'.number_format((float) $amount, 2);
    }
};

?>

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">Billing Statements</h1>
        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Find a guest or transaction, select a Reservation or Booking, then review its complete historical statement.
        </p>
    </div>

    @if ($errorMessage)
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
            {{ $errorMessage }}
        </div>
    @endif

    <flux:card>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-7">
            <flux:input
                label="Guest / Reference"
                placeholder="Name, email, contact, reservation or booking ref"
                wire:model.live.debounce.300ms="search"
                clearable
                class="xl:col-span-2"
            />
            <flux:input type="date" label="From" wire:model.live="fromDate" />
            <flux:input type="date" label="To" wire:model.live="toDate" />
            <flux:select label="Transaction" wire:model.live="transactionType">
                <option value="all">Reservations & Bookings</option>
                <option value="reservation">Reservations</option>
                <option value="booking">Bookings</option>
            </flux:select>
            <flux:select label="Balance" wire:model.live="paymentStatus">
                <option value="all">All</option>
                <option value="paid">Paid / settled</option>
                <option value="unpaid">Outstanding</option>
            </flux:select>
            <flux:select label="Rows" wire:model.live="perPage">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </flux:select>
        </div>

        <div class="mt-4 flex justify-end">
            <flux:button type="button" variant="ghost" wire:click="clearFilters">Clear Filters</flux:button>
        </div>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(26rem,0.9fr)]">
        <flux:card class="overflow-hidden p-0">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="font-semibold">Historical Transactions</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                    <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-950/60">
                        <tr>
                            <th class="px-4 py-3 text-left"><button wire:click="sortBy('date')">Date {{ $this->sortIndicator('date') }}</button></th>
                            <th class="px-4 py-3 text-left"><button wire:click="sortBy('transaction_type')">Type {{ $this->sortIndicator('transaction_type') }}</button></th>
                            <th class="px-4 py-3 text-left"><button wire:click="sortBy('guest_name')">Guest {{ $this->sortIndicator('guest_name') }}</button></th>
                            <th class="px-4 py-3 text-right"><button wire:click="sortBy('amount')">Total {{ $this->sortIndicator('amount') }}</button></th>
                            <th class="px-4 py-3 text-right"><button wire:click="sortBy('amount_due')">Balance {{ $this->sortIndicator('amount_due') }}</button></th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($records as $record)
                            @php($selected = $selectedTransactionType === $record->transaction_type && $selectedTransactionId === (int) $record->transaction_id)
                            <tr
                                wire:key="transaction-{{ $record->transaction_type }}-{{ $record->transaction_id }}"
                                @class([
                                    'transition-colors',
                                    'bg-blue-50 ring-1 ring-inset ring-blue-200 dark:bg-blue-950/30 dark:ring-blue-800' => $selected,
                                ])
                            >
                                <td class="whitespace-nowrap px-4 py-3">{{ $record->transaction_date }}</td>
                                <td class="px-4 py-3">
                                    <flux:badge color="{{ $record->transaction_type === 'booking' ? 'blue' : 'amber' }}">
                                        {{ ucfirst($record->transaction_type) }}
                                    </flux:badge>
                                    <div class="mt-1 text-xs text-zinc-500">{{ $record->reference_no }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ $record->first_name }} {{ $record->last_name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $record->contact_no }}</div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ $this->money($record->total_price) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ $this->money($record->amount_due) }}</td>
                                <td class="px-4 py-3">{{ $record->status }}</td>
                                <td class="px-4 py-3 text-right">
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        variant="{{ $selected ? 'primary' : 'ghost' }}"
                                        wire:click="selectTransaction('{{ $record->transaction_type }}', {{ $record->transaction_id }})"
                                    >
                                        Statement
                                    </flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-10 text-center text-zinc-500">No historical transaction matches the filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">
                {{ $records->links() }}
            </div>
        </flux:card>

        <flux:card>
            @if (! $statement)
                <div class="py-12 text-center text-sm text-zinc-500">
                    Select a Reservation or Booking to view its detailed statement.
                </div>
            @else
                <div class="space-y-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-zinc-500">{{ $statement['transaction_type'] }}</p>
                            <h2 class="text-xl font-semibold">{{ $statement['reference_no'] }}</h2>
                            <p class="text-sm text-zinc-500">{{ $statement['guest_name'] }}</p>
                        </div>
                        @if ($statement['transaction_type'] === 'Booking' && isset($statement['booking']))
                            <flux:button
                                href="{{ route('print.billing', $statement['booking']) }}"
                                target="_blank"
                                rel="noopener"
                                variant="primary"
                            >
                                Print Statement
                            </flux:button>
                        @endif
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
                            <p class="text-xs uppercase text-zinc-500">Status</p>
                            <p class="font-semibold">{{ $statement['transaction_status'] }}</p>
                        </div>
                        <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
                            <p class="text-xs uppercase text-zinc-500">Contact</p>
                            <p class="font-semibold">{{ $statement['guest_contact'] ?: '—' }}</p>
                            <p class="text-xs text-zinc-500">{{ $statement['guest_email'] }}</p>
                        </div>
                    </div>

                    <section>
                        <h3 class="mb-2 font-semibold">Facility Charges</h3>
                        <div class="space-y-2">
                            @forelse ($statement['facility_lines'] as $line)
                                <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="font-medium">{{ $line['facility'] }}</p>
                                            <p class="text-xs text-zinc-500">{{ $line['rate_type'] }} · {{ $line['check_in_date'] }} to {{ $line['check_out_date'] }} · {{ $line['status'] }}</p>
                                        </div>
                                        <p class="font-semibold">{{ $this->money($line['line_total']) }}</p>
                                    </div>
                                    @if ((float) $line['discount_amount'] > 0 || (float) $line['extra_guest_fee'] > 0)
                                        <p class="mt-2 text-xs text-zinc-500">
                                            Base {{ $this->money($line['base_price']) }}
                                            · Discount −{{ $this->money($line['discount_amount']) }}
                                            · Extra guests +{{ $this->money($line['extra_guest_fee']) }}
                                        </p>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-zinc-500">No facility lines.</p>
                            @endforelse
                        </div>
                    </section>

                    @if (($statement['amenity_lines'] ?? collect())->isNotEmpty())
                        <section>
                            <h3 class="mb-2 font-semibold">Amenities</h3>
                            <div class="space-y-2">
                                @foreach ($statement['amenity_lines'] as $line)
                                    <div class="flex justify-between gap-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                                        <span>{{ $line['amenity'] }} × {{ $line['quantity'] }} · {{ $line['request_status'] }}</span>
                                        <span class="font-medium">{{ $this->money($line['line_total']) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if (($statement['fine_lines'] ?? collect())->isNotEmpty())
                        <section>
                            <h3 class="mb-2 font-semibold">Posted Fines</h3>
                            <div class="space-y-2">
                                @foreach ($statement['fine_lines'] as $line)
                                    <div class="flex justify-between gap-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                                        <span>{{ $line['description'] }} × {{ $line['quantity'] }}</span>
                                        <span class="font-medium">{{ $this->money($line['total_charge']) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if ($statement['entrance_slip'])
                        <section>
                            <h3 class="mb-2 font-semibold">Entrance Slip</h3>
                            <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                                Slip #{{ $statement['entrance_slip']->entrance_slip_id }}
                                · {{ $statement['entrance_slip']->status }}
                                · {{ $this->money($statement['entrance_slip']->total_price) }}
                            </div>
                        </section>
                    @endif

                    <section>
                        <h3 class="mb-2 font-semibold">Payments / GCash</h3>
                        <div class="space-y-2">
                            @forelse ($statement['payment_lines'] as $line)
                                <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                                    <div class="flex justify-between gap-3">
                                        <span>{{ $line['payment_ref_no'] }} · {{ $line['mode'] }}</span>
                                        <span class="font-medium">{{ $this->money($line['amount_paid']) }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $line['date_paid'] }}
                                        @if (($line['reference_number'] ?? null)) · Ref {{ $line['reference_number'] }} @endif
                                        @if (($line['status'] ?? null)) · {{ $line['status'] }} @endif
                                    </p>
                                </div>
                            @empty
                                <p class="text-sm text-zinc-500">No payments recorded.</p>
                            @endforelse
                        </div>
                    </section>

                    @if ($statement['credits']->isNotEmpty())
                        <section>
                            <h3 class="mb-2 font-semibold">Same-Transaction Credits</h3>
                            <div class="space-y-2">
                                @foreach ($statement['credits'] as $credit)
                                    <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                                        <div class="flex justify-between gap-3">
                                            <span>{{ $credit->reason }}</span>
                                            <span class="font-medium">{{ $this->money($credit->original_amount) }}</span>
                                        </div>
                                        <p class="mt-1 text-xs text-zinc-500">
                                            Remaining {{ $this->money($credit->remaining_amount) }} · {{ $credit->status }}
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if ($statement['adjustments']->isNotEmpty())
                        <section>
                            <h3 class="mb-2 font-semibold">Adjustments</h3>
                            <div class="space-y-2">
                                @foreach ($statement['adjustments'] as $adjustment)
                                    <div class="flex justify-between gap-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                                        <span>{{ $adjustment->direction }} · {{ $adjustment->reason }}</span>
                                        <span class="font-medium">{{ $this->money($adjustment->amount) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    <div class="rounded-xl border border-zinc-300 p-4 dark:border-zinc-700">
                        <div class="flex justify-between py-1"><span>Charges</span><span>{{ $this->money($statement['ledger']['charges']) }}</span></div>
                        <div class="flex justify-between py-1"><span>Discounts / credit adjustments</span><span>−{{ $this->money($statement['ledger']['discounts']) }}</span></div>
                        <div class="flex justify-between py-1"><span>Applied transaction credit</span><span>−{{ $this->money($statement['ledger']['applied_credits']) }}</span></div>
                        <div class="flex justify-between py-1"><span>Verified payments</span><span>−{{ $this->money($statement['ledger']['verified_payments']) }}</span></div>
                        <div class="mt-2 flex justify-between border-t border-zinc-200 pt-3 text-base font-bold dark:border-zinc-800">
                            <span>Balance</span><span>{{ $this->money($statement['ledger']['balance']) }}</span>
                        </div>
                    </div>
                </div>
            @endif
        </flux:card>
    </div>
</div>

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

    #[Url(as: 'q', except: '')] public string $search = '';
    #[Url(as: 'from', except: '')] public string $fromDate = '';
    #[Url(as: 'to', except: '')] public string $toDate = '';
    #[Url(as: 'payment_status', except: 'all')] public string $paymentStatus = 'all';
    #[Url(as: 'transaction_type', except: 'all')] public string $transactionType = 'all';
    #[Url(as: 'sort', except: 'date')] public string $sortField = 'date';
    #[Url(as: 'direction', except: 'desc')] public string $sortDirection = 'desc';
    #[Url(as: 'per_page', except: 10)] public int $perPage = 10;

    public ?string $selectedType = null;
    public ?int $selectedId = null;
    public ?string $errorMessage = null;

    public function with(): array
    {
        return [
            'records' => app(GuestTransactionHistoryService::class)->paginated(
                $this->filters(),
                $this->perPage,
                $this->sortField,
                $this->sortDirection,
            ),
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

    public function selectTransaction(string $type, int $id): void
    {
        if (! in_array($type, ['reservation', 'booking'], true)) {
            return;
        }

        $this->selectedType = $type;
        $this->selectedId = $id;
        $this->errorMessage = null;
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, [
            'date', 'transaction_type', 'guest_name', 'amount', 'amount_due', 'payment_status',
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
        return $this->sortField !== $field
            ? '↕'
            : ($this->sortDirection === 'asc' ? '↑' : '↓');
    }

    public function clearFilters(): void
    {
        $this->reset([
            'search', 'fromDate', 'toDate', 'paymentStatus',
            'transactionType', 'selectedType', 'selectedId', 'errorMessage',
        ]);
        $this->sortField = 'date';
        $this->sortDirection = 'desc';
        $this->perPage = 10;
        $this->resetPage();
    }

    public function selectedStatement(): ?array
    {
        if ($this->selectedType === null || $this->selectedId === null) {
            return null;
        }

        try {
            return app(GuestTransactionHistoryService::class)->statement(
                $this->selectedType,
                $this->selectedId,
            );
        } catch (\Throwable $exception) {
            $this->errorMessage = $exception->getMessage();

            return null;
        }
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
        <h1 class="text-2xl font-semibold tracking-tight">Billing Statement</h1>
        <p class="mt-1 text-sm text-zinc-500">
            Search a guest or reference, choose a historical Reservation or Booking, then review its authoritative detailed statement.
        </p>
    </div>

    @if ($errorMessage)
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200">{{ $errorMessage }}</div>
    @endif

    <flux:card>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-7">
            <flux:input wire:model.live.debounce.300ms="search" label="Guest / Reference" placeholder="Name, email, phone, R-ref or B-ref" clearable class="xl:col-span-2" />
            <flux:input wire:model.live="fromDate" type="date" label="From" />
            <flux:input wire:model.live="toDate" type="date" label="To" />
            <flux:select wire:model.live="transactionType" label="Transaction">
                <option value="all">Reservations & Bookings</option>
                <option value="reservation">Reservations</option>
                <option value="booking">Bookings</option>
            </flux:select>
            <flux:select wire:model.live="paymentStatus" label="Balance">
                <option value="all">All</option>
                <option value="paid">Paid / settled</option>
                <option value="unpaid">Outstanding</option>
            </flux:select>
            <flux:select wire:model.live="perPage" label="Rows">
                @foreach ([10, 25, 50, 100] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach
            </flux:select>
        </div>
        <div class="mt-4 flex justify-end">
            <flux:button type="button" variant="ghost" wire:click="clearFilters">Clear filters</flux:button>
        </div>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
        <flux:card class="overflow-hidden p-0">
            <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                <flux:heading size="lg">Historical transactions</flux:heading>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                    <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-950/60">
                        <tr>
                            <th class="px-4 py-3 text-left"><button wire:click="sortBy('date')">Date {{ $this->sortIndicator('date') }}</button></th>
                            <th class="px-4 py-3 text-left"><button wire:click="sortBy('transaction_type')">Type {{ $this->sortIndicator('transaction_type') }}</button></th>
                            <th class="px-4 py-3 text-left"><button wire:click="sortBy('guest_name')">Guest {{ $this->sortIndicator('guest_name') }}</button></th>
                            <th class="px-4 py-3 text-right"><button wire:click="sortBy('amount')">Total {{ $this->sortIndicator('amount') }}</button></th>
                            <th class="px-4 py-3 text-right"><button wire:click="sortBy('amount_due')">Due {{ $this->sortIndicator('amount_due') }}</button></th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($records as $record)
                            @php($selected = $selectedType === $record->transaction_type && $selectedId === (int) $record->transaction_id)
                            <tr wire:key="history-{{ $record->transaction_type }}-{{ $record->transaction_id }}" @class([
                                'transition-colors',
                                'bg-emerald-50 dark:bg-emerald-950/30' => $selected,
                            ])>
                                <td class="whitespace-nowrap px-4 py-3">{{ $record->transaction_date }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium capitalize">{{ $record->transaction_type }}</div>
                                    <div class="text-xs text-zinc-500">{{ $record->reference_no }}</div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ $record->first_name }} {{ $record->last_name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $record->contact_no }}</div>
                                </td>
                                <td class="px-4 py-3 text-right">{{ $this->money($record->total_price) }}</td>
                                <td class="px-4 py-3 text-right">{{ $this->money($record->amount_due) }}</td>
                                <td class="px-4 py-3">
                                    <div>{{ $record->status }}</div>
                                    <div class="text-xs {{ (float) $record->amount_due <= 0 ? 'text-green-600' : 'text-amber-600' }}">
                                        {{ (float) $record->amount_due <= 0 ? 'Settled' : 'Outstanding' }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <flux:button type="button" size="sm" variant="{{ $selected ? 'primary' : 'ghost' }}" wire:click="selectTransaction('{{ $record->transaction_type }}', {{ $record->transaction_id }})">
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
            <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800">{{ $records->links() }}</div>
        </flux:card>

        <flux:card class="min-w-0">
            @if (! $statement)
                <div class="py-16 text-center text-sm text-zinc-500">Select a transaction to view its detailed statement.</div>
            @else
                <div class="space-y-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $statement['transaction_type'] }}</p>
                            <flux:heading size="xl">{{ $statement['reference_no'] }}</flux:heading>
                            <p class="mt-1 text-sm text-zinc-500">{{ $statement['guest_name'] }} · {{ $statement['guest_contact'] }}</p>
                        </div>
                        <flux:badge color="{{ $statement['ledger']['balance'] === '0.00' ? 'green' : 'amber' }}">{{ $statement['transaction_status'] }}</flux:badge>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-800"><p class="text-xs text-zinc-500">Charges</p><p class="font-semibold">{{ $this->money($statement['ledger']['charges']) }}</p></div>
                        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-800"><p class="text-xs text-zinc-500">Discounts</p><p class="font-semibold">{{ $this->money($statement['ledger']['discounts']) }}</p></div>
                        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-800"><p class="text-xs text-zinc-500">Balance</p><p class="font-semibold">{{ $this->money($statement['ledger']['balance']) }}</p></div>
                    </div>

                    <section>
                        <h3 class="mb-2 font-semibold">Facilities</h3>
                        <div class="space-y-2">
                            @forelse ($statement['facility_lines'] as $line)
                                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="font-medium">{{ $line['facility'] }}</p>
                                            <p class="text-xs text-zinc-500">{{ $line['facility_type'] }} · {{ $line['rate_type'] }} · {{ $line['check_in_date'] }} to {{ $line['check_out_date'] }} · {{ $line['status'] }}</p>
                                        </div>
                                        <span class="font-semibold">{{ $this->money($line['line_total']) }}</span>
                                    </div>
                                    @if (($line['occupants'] ?? collect())->isNotEmpty())
                                        <p class="mt-2 text-xs text-zinc-500">Room occupants: {{ $line['occupants']->map(fn ($o) => trim($o->first_name.' '.$o->last_name))->join(', ') }}</p>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-zinc-500">No facility lines.</p>
                            @endforelse
                        </div>
                    </section>

                    @if (($statement['entrance_slip'] ?? null) !== null)
                        <section>
                            <h3 class="mb-2 font-semibold">Entrance charges</h3>
                            <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                                Entrance Slip #{{ $statement['entrance_slip']->entrance_slip_id }}
                                · {{ $this->money($statement['entrance_slip']->total_price) }}
                                · {{ $statement['entrance_slip']->status }}
                            </div>
                        </section>
                    @endif

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
                            <h3 class="mb-2 font-semibold">Posted fines</h3>
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

                    <section>
                        <h3 class="mb-2 font-semibold">Payments / GCash</h3>
                        <div class="space-y-2">
                            @forelse ($statement['payment_lines'] as $line)
                                <div class="flex justify-between gap-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                                    <div>
                                        <p class="font-medium">{{ $line['payment_ref_no'] }} · {{ $line['mode'] }}</p>
                                        <p class="text-xs text-zinc-500">{{ $line['reference_number'] ? 'Ref '.$line['reference_number'].' · ' : '' }}{{ $line['status'] ?? 'Verified' }}</p>
                                    </div>
                                    <span class="font-semibold">{{ $this->money($line['amount_paid']) }}</span>
                                </div>
                            @empty
                                <p class="text-sm text-zinc-500">No payments recorded.</p>
                            @endforelse
                        </div>
                    </section>

                    @if ($statement['credits']->isNotEmpty())
                        <section>
                            <h3 class="mb-2 font-semibold">Transaction credits</h3>
                            <div class="space-y-2">
                                @foreach ($statement['credits'] as $credit)
                                    <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                                        <div class="flex justify-between gap-3"><span>{{ $credit->reason }}</span><span class="font-medium">{{ $this->money($credit->original_amount) }}</span></div>
                                        <p class="mt-1 text-xs text-zinc-500">Remaining {{ $this->money($credit->remaining_amount) }} · {{ $credit->status }} · {{ $credit->allocations->count() }} allocation(s)</p>
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

                    <div class="rounded-xl border-2 border-zinc-300 p-4 dark:border-zinc-700">
                        <div class="flex justify-between py-1"><span>Final transaction total</span><span class="font-semibold">{{ $this->money($statement['ledger']['total']) }}</span></div>
                        <div class="flex justify-between py-1"><span>Verified settled payments</span><span>{{ $this->money($statement['ledger']['verified_payments']) }}</span></div>
                        <div class="flex justify-between py-1"><span>Applied transaction credits</span><span>{{ $this->money($statement['ledger']['applied_credits']) }}</span></div>
                        <div class="mt-2 flex justify-between border-t border-zinc-200 pt-3 text-lg font-bold dark:border-zinc-800"><span>Balance</span><span>{{ $this->money($statement['ledger']['balance']) }}</span></div>
                    </div>
                </div>
            @endif
        </flux:card>
    </div>
</div>

<?php

use App\Services\BillingStatementService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $fromDate = '';
    public string $toDate = '';
    public string $paymentStatus = 'all';
    public string $transactionType = 'all';
    public ?int $selectedBookingId = null;
    public ?string $errorMessage = null;

    public function with(): array
    {
        return [
            'records' => $this->paginatedRecords(),
            'statement' => $this->selectedStatement(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFromDate(): void
    {
        $this->resetPage();
    }

    public function updatedToDate(): void
    {
        $this->resetPage();
    }

    public function updatedPaymentStatus(): void
    {
        $this->resetPage();
    }

    public function updatedTransactionType(): void
    {
        $this->resetPage();
    }

    public function selectBooking(int $bookingId): void
    {
        $this->selectedBookingId = $bookingId;
        $this->errorMessage = null;
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->fromDate = '';
        $this->toDate = '';
        $this->paymentStatus = 'all';
        $this->transactionType = 'all';
        $this->resetPage();
    }

    public function paginatedRecords(): LengthAwarePaginator
    {
        $records = app(BillingStatementService::class)->records($this->filters());
        $page = $this->getPage();
        $perPage = 10;

        return new LengthAwarePaginator(
            $records->forPage($page, $perPage)->values(),
            $records->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page']
        );
    }

    public function selectedStatement(): ?array
    {
        if ($this->selectedBookingId === null) {
            return null;
        }

        try {
            return app(BillingStatementService::class)->statementForBooking($this->selectedBookingId);
        } catch (Throwable $exception) {
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
        return '₱' . number_format((float) $amount, 2);
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-zinc-50">Billing Statements</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                View billed bookings, amenity requests, and fines. Print a statement only after checking the booking balance.
            </p>
        </div>
    </div>

    @if ($errorMessage)
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
            {{ $errorMessage }}
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="grid gap-4 md:grid-cols-5">
            <div class="md:col-span-2">
                <flux:input label="Search" placeholder="Guest, booking ref, billing ref..." wire:model.live.debounce.300ms="search" />
            </div>

            <div>
                <flux:input type="date" label="From" wire:model.live="fromDate" />
            </div>

            <div>
                <flux:input type="date" label="To" wire:model.live="toDate" />
            </div>

            <div class="flex items-end">
                <flux:button type="button" variant="outline" wire:click="clearFilters" class="w-full">Clear</flux:button>
            </div>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                Transaction Type
                <select wire:model.live="transactionType" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                    <option value="all">All</option>
                    <option value="booking">Bookings</option>
                    <option value="amenity_request">Amenity Requests</option>
                    <option value="fine">Fines</option>
                </select>
            </label>

            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                Payment Status
                <select wire:model.live="paymentStatus" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-950">
                    <option value="all">All</option>
                    <option value="paid">Paid</option>
                    <option value="unpaid">Unpaid</option>
                </select>
            </label>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-50">Billing Records</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-800">
                    <thead class="bg-zinc-50 dark:bg-zinc-950/60">
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-zinc-500">
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Guest</th>
                            <th class="px-4 py-3">Description</th>
                            <th class="px-4 py-3 text-right">Amount</th>
                            <th class="px-4 py-3 text-right">Due</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($records as $record)
                            <tr class="align-top">
                                <td class="whitespace-nowrap px-4 py-3">{{ $record['date'] ?? 'N/A' }}</td>
                                <td class="whitespace-nowrap px-4 py-3">{{ $record['transaction_type'] }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-50">{{ $record['guest_name'] }}</div>
                                    <div class="text-xs text-zinc-500">{{ $record['booking_ref_no'] }}</div>
                                </td>
                                <td class="px-4 py-3">{{ $record['description'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ $this->money($record['amount']) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">{{ $this->money($record['amount_due']) }}</td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'rounded-full px-2 py-1 text-xs font-medium',
                                        'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' => $record['payment_status'] === 'Paid',
                                        'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $record['payment_status'] !== 'Paid',
                                    ])>
                                        {{ $record['payment_status'] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if (($record['booking_id'] ?? 0) > 0)
                                        <flux:button size="sm" variant="outline" wire:click="selectBooking({{ $record['booking_id'] }})">
                                            View Statement
                                        </flux:button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-zinc-500">No billing records found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">
                {{ $records->links() }}
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="font-semibold text-zinc-900 dark:text-zinc-50">Printable Billing Statement</h2>
                @if ($statement)
                    <flux:button type="button" variant="primary" onclick="window.print()">Print</flux:button>
                @endif
            </div>

            @if (! $statement)
                <div class="p-8 text-center text-sm text-zinc-500">
                    Select a billing record to preview the statement.
                </div>
            @else
                <div id="billing-statement" class="space-y-6 p-5 text-sm">
                    <div class="text-center">
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-zinc-50">Olaer Spring Resort</h3>
                        <p class="text-zinc-600 dark:text-zinc-400">Billing Statement</p>
                        <p class="text-xs text-zinc-500">Generated: {{ $statement['generated_at'] }}</p>
                    </div>

                    <div class="grid gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-800 md:grid-cols-2">
                        <div>
                            <div class="text-xs uppercase text-zinc-500">Booking Reference</div>
                            <div class="font-semibold">{{ $statement['booking']->b_ref_no }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-zinc-500">Payment Status</div>
                            <div class="font-semibold">{{ $statement['payment_status'] }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-zinc-500">Guest</div>
                            <div class="font-semibold">{{ $statement['guest_name'] }}</div>
                            <div class="text-xs text-zinc-500">{{ $statement['guest_contact'] }} / {{ $statement['guest_email'] }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-zinc-500">Booking Date</div>
                            <div class="font-semibold">{{ optional($statement['booking']->booking_date)->toDateString() }}</div>
                        </div>
                    </div>

                    <section>
                        <h4 class="mb-2 font-semibold">Facilities</h4>
                        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                                <thead class="bg-zinc-50 dark:bg-zinc-950/60">
                                    <tr class="text-left text-xs uppercase text-zinc-500">
                                        <th class="px-3 py-2">Facility</th>
                                        <th class="px-3 py-2">Rate</th>
                                        <th class="px-3 py-2">Dates</th>
                                        <th class="px-3 py-2 text-right">Base</th>
                                        <th class="px-3 py-2 text-right">Discount</th>
                                        <th class="px-3 py-2 text-right">Extra</th>
                                        <th class="px-3 py-2 text-right">Line Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($statement['facility_lines'] as $line)
                                        <tr>
                                            <td class="px-3 py-2">
                                                <div class="font-medium">{{ $line['facility'] }}</div>
                                                <div class="text-xs text-zinc-500">{{ $line['facility_type'] }} / {{ $line['status'] }}</div>
                                            </td>
                                            <td class="px-3 py-2">{{ $line['rate_type'] }}</td>
                                            <td class="px-3 py-2">{{ $line['check_in_date'] }} to {{ $line['check_out_date'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $this->money($line['base_price']) }}</td>
                                            <td class="px-3 py-2 text-right">{{ $this->money($line['discount_amount']) }}</td>
                                            <td class="px-3 py-2 text-right">{{ $this->money($line['extra_guest_fee']) }}</td>
                                            <td class="px-3 py-2 text-right">
                                                @if ($line['has_snapshot'])
                                                    {{ $this->money($line['line_total']) }}
                                                @else
                                                    <span class="text-zinc-500">Use recorded booking total</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section>
                        <h4 class="mb-2 font-semibold">Amenity Requests</h4>
                        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                                <thead class="bg-zinc-50 dark:bg-zinc-950/60">
                                    <tr class="text-left text-xs uppercase text-zinc-500">
                                        <th class="px-3 py-2">Amenity</th>
                                        <th class="px-3 py-2">Facility</th>
                                        <th class="px-3 py-2">Status</th>
                                        <th class="px-3 py-2 text-right">Qty</th>
                                        <th class="px-3 py-2 text-right">Unit</th>
                                        <th class="px-3 py-2 text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @forelse ($statement['amenity_lines'] as $line)
                                        <tr>
                                            <td class="px-3 py-2">{{ $line['amenity'] }}</td>
                                            <td class="px-3 py-2">{{ $line['facility'] }}</td>
                                            <td class="px-3 py-2">{{ $line['request_status'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $line['quantity'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $this->money($line['unit_price']) }}</td>
                                            <td class="px-3 py-2 text-right">{{ $this->money($line['line_total']) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="px-3 py-4 text-center text-zinc-500">No amenity requests.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section>
                        <h4 class="mb-2 font-semibold">Fines</h4>
                        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                                <thead class="bg-zinc-50 dark:bg-zinc-950/60">
                                    <tr class="text-left text-xs uppercase text-zinc-500">
                                        <th class="px-3 py-2">Fine</th>
                                        <th class="px-3 py-2">Facility</th>
                                        <th class="px-3 py-2">Checked</th>
                                        <th class="px-3 py-2 text-right">Qty</th>
                                        <th class="px-3 py-2 text-right">Charge</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @forelse ($statement['fine_lines'] as $line)
                                        <tr>
                                            <td class="px-3 py-2">
                                                <div>{{ $line['description'] }}</div>
                                                <div class="text-xs text-zinc-500">Reported by: {{ $line['reported_by'] }}</div>
                                            </td>
                                            <td class="px-3 py-2">{{ $line['facility'] }}</td>
                                            <td class="px-3 py-2">{{ $line['date_checked'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $line['quantity'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $this->money($line['total_charge']) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-3 py-4 text-center text-zinc-500">No fines.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section>
                        <h4 class="mb-2 font-semibold">Payments</h4>
                        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-800">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                                <thead class="bg-zinc-50 dark:bg-zinc-950/60">
                                    <tr class="text-left text-xs uppercase text-zinc-500">
                                        <th class="px-3 py-2">Payment Ref</th>
                                        <th class="px-3 py-2">Mode</th>
                                        <th class="px-3 py-2">Date</th>
                                        <th class="px-3 py-2">Received By</th>
                                        <th class="px-3 py-2 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @forelse ($statement['payment_lines'] as $line)
                                        <tr>
                                            <td class="px-3 py-2">
                                                <div>{{ $line['payment_ref_no'] }}</div>
                                                @if ($line['reference_number'])
                                                    <div class="text-xs text-zinc-500">Ref: {{ $line['reference_number'] }}</div>
                                                @endif
                                            </td>
                                            <td class="px-3 py-2">{{ $line['mode'] }}</td>
                                            <td class="px-3 py-2">{{ $line['date_paid'] }}</td>
                                            <td class="px-3 py-2">{{ $line['received_by'] }}</td>
                                            <td class="px-3 py-2 text-right">{{ $this->money($line['amount_paid']) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="px-3 py-4 text-center text-zinc-500">No payments recorded.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <div class="ml-auto max-w-sm rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                        <div class="flex justify-between py-1">
                            <span>Recorded Total</span>
                            <span class="font-medium">{{ $this->money($statement['total_price']) }}</span>
                        </div>
                        <div class="flex justify-between py-1">
                            <span>Total Paid</span>
                            <span class="font-medium">{{ $this->money($statement['total_paid']) }}</span>
                        </div>
                        <div class="flex justify-between border-t border-zinc-200 py-2 text-base font-bold dark:border-zinc-800">
                            <span>Amount Due</span>
                            <span>{{ $this->money($statement['amount_due']) }}</span>
                        </div>
                    </div>

                    <p class="text-xs text-zinc-500">
                        Note: Booking total and amount due are the source of truth. Facility and amenity line prices use saved snapshots for new records after this module is installed. Older records without snapshots use available fallback values.
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>

<?php

use App\Models\EntranceSlip;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Services\PaymentWorkflowService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Entrance Slip Payments - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: 'Unpaid')]
    public string $statusFilter = 'Unpaid';

    #[Url(as: 'date_from', except: '')]
    public string $dateFrom = '';

    #[Url(as: 'date_to', except: '')]
    public string $dateTo = '';

    #[Url(as: 'sort', except: 'entrance_slip_id')]
    public string $sortField = 'entrance_slip_id';

    #[Url(as: 'direction', except: 'desc')]
    public string $sortDirection = 'desc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $selectedSlipId = null;
    public string $modeOfPaymentId = '';
    public string $referenceNumber = '';
    public ?int $selectedPaymentId = null;

    public function mount(): void
    {
        $this->modeOfPaymentId = (string) (
            ModeOfPayment::query()
                ->where('mode_of_payment', 'Cash')
                ->value('mode_of_payment_id')
                ?? ModeOfPayment::query()
                    ->orderBy('mode_of_payment_id')
                    ->value('mode_of_payment_id')
                ?? ''
        );

        $entranceSlipId = request()->integer('entrance_slip');

        if ($entranceSlipId > 0) {
            $this->selectSlip($entranceSlipId);
        }
    }

    #[Computed]
    public function slips(): LengthAwarePaginator
    {
        $allowedSorts = [
            'entrance_slip_id',
            'date_created',
            'total_guests',
            'total_price',
            'amount_due',
            'status',
        ];

        $sortField = in_array($this->sortField, $allowedSorts, true)
            ? $this->sortField
            : 'entrance_slip_id';

        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        $query = EntranceSlip::query()
            ->select('tbl_entrance_slip.*')
            ->selectRaw(
                '(COALESCE(no_of_adult, 0) + COALESCE(no_of_children, 0) + COALESCE(no_of_PWD_SC, 0)) as total_guests'
            )
            ->with([
                'createdBy',
                'handledBy',
                'payments.modeOfPayment',
            ])
            ->when(
                $this->statusFilter !== '',
                fn ($query) => $query->where(
                    'tbl_entrance_slip.status',
                    $this->statusFilter,
                ),
            )
            ->when(
                $this->dateFrom !== '',
                fn ($query) => $query->whereDate(
                    'tbl_entrance_slip.date_created',
                    '>=',
                    $this->dateFrom,
                ),
            )
            ->when(
                $this->dateTo !== '',
                fn ($query) => $query->whereDate(
                    'tbl_entrance_slip.date_created',
                    '<=',
                    $this->dateTo,
                ),
            )
            ->when(trim($this->search) !== '', function ($query): void {
                $searchText = trim($this->search);
                $like = '%'.$searchText.'%';
                $numeric = ctype_digit($searchText)
                    ? (int) $searchText
                    : null;

                $query->where(function ($query) use ($like, $numeric): void {
                    $query->where('tbl_entrance_slip.status', 'like', $like)
                        ->orWhere('tbl_entrance_slip.date_created', 'like', $like)
                        ->orWhere('tbl_entrance_slip.time_created', 'like', $like)
                        ->orWhere('tbl_entrance_slip.total_price', 'like', $like)
                        ->orWhere('tbl_entrance_slip.amount_due', 'like', $like)
                        ->orWhereHas('createdBy', function ($query) use ($like): void {
                            $query->where('first_name', 'like', $like)
                                ->orWhere('middle_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like)
                                ->orWhere('username', 'like', $like);
                        })
                        ->orWhereHas('handledBy', function ($query) use ($like): void {
                            $query->where('first_name', 'like', $like)
                                ->orWhere('middle_name', 'like', $like)
                                ->orWhere('last_name', 'like', $like)
                                ->orWhere('username', 'like', $like);
                        })
                        ->orWhereHas('payments', function ($query) use ($like): void {
                            $query->where('p_ref_no', 'like', $like)
                                ->orWhere('reference_number', 'like', $like);
                        });

                    if ($numeric !== null) {
                        $query->orWhere(
                            'tbl_entrance_slip.entrance_slip_id',
                            $numeric,
                        );
                    }
                });
            });

        match ($sortField) {
            'date_created' => $query
                ->orderBy('tbl_entrance_slip.date_created', $direction)
                ->orderBy('tbl_entrance_slip.time_created', $direction),
            'total_guests' => $query->orderBy('total_guests', $direction),
            'total_price' => $query->orderBy(
                'tbl_entrance_slip.total_price',
                $direction,
            ),
            'amount_due' => $query->orderBy(
                'tbl_entrance_slip.amount_due',
                $direction,
            ),
            'status' => $query->orderBy(
                'tbl_entrance_slip.status',
                $direction,
            ),
            default => $query->orderBy(
                'tbl_entrance_slip.entrance_slip_id',
                $direction,
            ),
        };

        return $query->paginate($perPage);
    }

    #[Computed]
    public function paymentModes(): Collection
    {
        return ModeOfPayment::query()
            ->orderBy('mode_of_payment')
            ->get();
    }

    #[Computed]
    public function selectedSlip(): ?EntranceSlip
    {
        if ($this->selectedSlipId === null) {
            return null;
        }

        return EntranceSlip::query()
            ->with([
                'createdBy',
                'handledBy',
                'details.entranceFee',
                'details.discount',
                'payments.modeOfPayment',
                'payments.user',
                'payments.verifier',
            ])
            ->find($this->selectedSlipId);
    }

    #[Computed]
    public function selectedPayment(): ?Payment
    {
        if ($this->selectedPaymentId === null) {
            return null;
        }

        return Payment::query()
            ->with([
                'modeOfPayment',
                'user',
                'verifier',
                'entranceSlip',
            ])
            ->find($this->selectedPaymentId);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 10;
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'Unpaid';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->sortField = 'entrance_slip_id';
        $this->sortDirection = 'desc';
        $this->perPage = 10;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowedSorts = [
            'entrance_slip_id',
            'date_created',
            'total_guests',
            'total_price',
            'amount_due',
            'status',
        ];

        if (! in_array($field, $allowedSorts, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc'
                ? 'desc'
                : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = $field === 'entrance_slip_id'
                ? 'desc'
                : 'asc';
        }

        $this->resetPage();
    }

    public function selectSlip(int $slipId): void
    {
        $slip = EntranceSlip::query()->find($slipId);

        if ($slip === null) {
            session()->flash('error', 'Entrance slip not found.');
            return;
        }

        $this->selectedSlipId = (int) $slip->entrance_slip_id;
        $this->selectedPaymentId = null;
        $this->referenceNumber = '';

        $cashModeId = ModeOfPayment::query()
            ->where('mode_of_payment', 'Cash')
            ->value('mode_of_payment_id');

        if ($cashModeId !== null) {
            $this->modeOfPaymentId = (string) $cashModeId;
        }

        unset($this->selectedSlip, $this->selectedPayment);
        $this->resetValidation();
    }

    public function clearSelection(): void
    {
        $this->selectedSlipId = null;
        $this->selectedPaymentId = null;
        $this->referenceNumber = '';

        unset($this->selectedSlip, $this->selectedPayment);
        $this->resetValidation();
    }

    public function recordPayment(
        PaymentWorkflowService $paymentWorkflow,
    ): void {
        $validated = $this->validate([
            'selectedSlipId' => [
                'required',
                'integer',
                'exists:tbl_entrance_slip,entrance_slip_id',
            ],
            'modeOfPaymentId' => [
                'required',
                'integer',
                'exists:tbl_mode_of_payment,mode_of_payment_id',
            ],
            'referenceNumber' => ['nullable', 'string', 'max:50'],
        ], [
            'selectedSlipId.required' => 'Select an entrance slip first.',
            'modeOfPaymentId.required' => 'Mode of payment is required.',
        ]);

        $slip = EntranceSlip::query()
            ->findOrFail((int) $validated['selectedSlipId']);

        try {
            $payment = $paymentWorkflow->recordCashierPayment([
                'target_type' => 'entrance_slip',
                'target_id' => (int) $slip->entrance_slip_id,
                'amount_paid' => round((float) $slip->amount_due, 2),
                'mode_of_payment_id' => (int) $validated['modeOfPaymentId'],
                'reference_number' => trim(
                    (string) ($validated['referenceNumber'] ?? ''),
                ),
                'user_id' => (int) Auth::id(),
            ]);

            $this->selectedPaymentId = (int) $payment->payment_id;
            $this->referenceNumber = '';

            unset($this->selectedSlip, $this->selectedPayment, $this->slips);

            session()->flash(
                'success',
                'Entrance slip payment recorded successfully.',
            );
        } catch (\Throwable $exception) {
            $this->addError('payment', $exception->getMessage());
        }
    }

    public function formatSlipNumber(EntranceSlip $slip): string
    {
        $date = $slip->date_created instanceof Carbon
            ? $slip->date_created
            : Carbon::parse($slip->date_created);

        return 'ES-'
            .$date->format('Ymd')
            .'-'
            .str_pad(
                (string) $slip->entrance_slip_id,
                5,
                '0',
                STR_PAD_LEFT,
            );
    }

    public function totalGuests(EntranceSlip $slip): int
    {
        return (int) $slip->no_of_adult
            + (int) $slip->no_of_children
            + (int) $slip->no_of_PWD_SC;
    }

    public function fullName(mixed $user, string $fallback = 'Not assigned'): string
    {
        if ($user === null) {
            return $fallback;
        }

        return $user->full_name
            ?? trim(implode(' ', array_filter([
                $user->first_name,
                $user->middle_name,
                $user->last_name,
            ])))
            ?: ($user->username ?? $fallback);
    }

    public function statusColor(string $status): string
    {
        return match (strtolower($status)) {
            'paid' => 'green',
            'unpaid' => 'amber',
            default => 'zinc',
        };
    }

    public function paymentStatusColor(string $status): string
    {
        return match (strtolower($status)) {
            'verified' => 'green',
            'pending' => 'amber',
            'rejected' => 'red',
            default => 'zinc',
        };
    }

    public function sortIndicator(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">
                Entrance Slip Payments
            </h1>

            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                View entrance slips created by security guards and collect full payment.
            </p>
        </div>

        @if (Route::has('cashier.dashboard'))
            <flux:button
                href="{{ route('cashier.dashboard') }}"
                wire:navigate
                variant="ghost"
            >
                Back to Dashboard
            </flux:button>
        @endif
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            {{ session('error') }}
        </div>
    @endif

    @error('payment')
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,2fr)_minmax(22rem,1fr)]">
        <section class="min-w-0">
            <flux:card class="overflow-hidden p-0">
                <div class="border-b border-zinc-200 p-5 dark:border-zinc-800">
                    <div>
                        <h2 class="font-semibold">Entrance slip list</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Search, filter, sort, paginate, print, and select an unpaid slip.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                        <flux:input
                            wire:model.live.debounce.300ms="search"
                            label="Search"
                            placeholder="Slip, guard, cashier, receipt, reference"
                            clearable
                            class="xl:col-span-2"
                        />

                        <flux:select
                            wire:model.live="statusFilter"
                            label="Status"
                        >
                            <option value="">All slips</option>
                            <option value="Unpaid">Unpaid</option>
                            <option value="Paid">Paid</option>
                        </flux:select>

                        <flux:input
                            wire:model.live="dateFrom"
                            type="date"
                            label="From"
                        />

                        <flux:input
                            wire:model.live="dateTo"
                            type="date"
                            label="To"
                        />

                        <flux:select wire:model.live="perPage" label="Rows">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </flux:select>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <flux:button
                            wire:click="clearFilters"
                            variant="ghost"
                        >
                            Clear Filters
                        </flux:button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[78rem] text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                            <tr>
                                <th class="px-5 py-3">
                                    <button
                                        wire:click="sortBy('entrance_slip_id')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Slip {{ $this->sortIndicator('entrance_slip_id') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        wire:click="sortBy('date_created')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Created {{ $this->sortIndicator('date_created') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        wire:click="sortBy('total_guests')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Guests {{ $this->sortIndicator('total_guests') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">Tourism count</th>
                                <th class="px-5 py-3">Created by</th>
                                <th class="px-5 py-3">Handled by</th>

                                <th class="px-5 py-3">
                                    <button
                                        wire:click="sortBy('total_price')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Total {{ $this->sortIndicator('total_price') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        wire:click="sortBy('amount_due')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Due {{ $this->sortIndicator('amount_due') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        wire:click="sortBy('status')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Status {{ $this->sortIndicator('status') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->slips as $slip)
                                <tr wire:key="entrance-slip-{{ $slip->entrance_slip_id }}">
                                    <td class="px-5 py-4 font-medium">
                                        {{ $this->formatSlipNumber($slip) }}
                                    </td>

                                    <td class="px-5 py-4">
                                        <p>{{ $slip->date_created?->format('M d, Y') }}</p>
                                        <p class="text-xs text-zinc-500">
                                            {{ filled($slip->time_created)
                                                ? Carbon::parse($slip->time_created)->format('h:i A')
                                                : 'Unknown time' }}
                                        </p>
                                    </td>

                                    <td class="px-5 py-4">
                                        <p class="font-medium">{{ $this->totalGuests($slip) }}</p>
                                        <p class="text-xs text-zinc-500">
                                            A {{ $slip->no_of_adult }}
                                            · C {{ $slip->no_of_children }}
                                            · SC/PWD {{ $slip->no_of_PWD_SC }}
                                        </p>
                                    </td>

                                    <td class="px-5 py-4 text-xs text-zinc-500">
                                        M {{ $slip->no_of_Male }}
                                        · F {{ $slip->no_of_Female }}
                                        · T {{ $slip->no_of_Tourist }}
                                    </td>

                                    <td class="px-5 py-4">
                                        {{ $this->fullName($slip->createdBy, 'Unknown guard') }}
                                    </td>

                                    <td class="px-5 py-4">
                                        {{ $this->fullName($slip->handledBy, 'Waiting for cashier') }}
                                    </td>

                                    <td class="px-5 py-4 font-medium">
                                        ₱{{ number_format((float) $slip->total_price, 2) }}
                                    </td>

                                    <td class="px-5 py-4 font-medium">
                                        ₱{{ number_format((float) $slip->amount_due, 2) }}
                                    </td>

                                    <td class="px-5 py-4">
                                        <flux:badge
                                            color="{{ $this->statusColor((string) $slip->status) }}"
                                            size="sm"
                                        >
                                            {{ $slip->status }}
                                        </flux:badge>
                                    </td>

                                    <td class="px-5 py-4 text-right">
                                        <div class="flex justify-end gap-2">
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

                                            <flux:button
                                                wire:click="selectSlip({{ $slip->entrance_slip_id }})"
                                                size="sm"
                                                variant="{{ $slip->status === 'Unpaid' ? 'primary' : 'ghost' }}"
                                            >
                                                {{ $slip->status === 'Unpaid' ? 'Pay' : 'View' }}
                                            </flux:button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="px-5 py-12 text-center text-zinc-500">
                                        No entrance slip matches the selected filters.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                    <p class="text-sm text-zinc-500">
                        Showing
                        {{ $this->slips->firstItem() ?? 0 }}
                        to
                        {{ $this->slips->lastItem() ?? 0 }}
                        of
                        {{ $this->slips->total() }}
                        entrance slips
                    </p>

                    {{ $this->slips->links() }}
                </div>
            </flux:card>
        </section>

        <aside>
            <flux:card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Entrance slip details</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Full payment is required before admission.
                        </p>
                    </div>

                    @if ($this->selectedSlip !== null)
                        <flux:button
                            wire:click="clearSelection"
                            size="sm"
                            variant="ghost"
                        >
                            Close
                        </flux:button>
                    @endif
                </div>

                @if ($this->selectedSlip !== null)
                    <div class="mt-5 space-y-5">
                        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium">
                                        {{ $this->formatSlipNumber($this->selectedSlip) }}
                                    </p>
                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $this->selectedSlip->date_created?->format('M d, Y') }}
                                        ·
                                        {{ filled($this->selectedSlip->time_created)
                                            ? Carbon::parse($this->selectedSlip->time_created)->format('h:i A')
                                            : 'Unknown time' }}
                                    </p>
                                </div>

                                <flux:badge
                                    color="{{ $this->statusColor((string) $this->selectedSlip->status) }}"
                                    size="sm"
                                >
                                    {{ $this->selectedSlip->status }}
                                </flux:badge>
                            </div>

                            <dl class="mt-4 space-y-2 text-sm">
                                <div class="flex justify-between gap-4">
                                    <dt class="text-zinc-500">Created by</dt>
                                    <dd class="text-right font-medium">
                                        {{ $this->fullName($this->selectedSlip->createdBy, 'Unknown guard') }}
                                    </dd>
                                </div>

                                <div class="flex justify-between gap-4">
                                    <dt class="text-zinc-500">Handled by</dt>
                                    <dd class="text-right font-medium">
                                        {{ $this->fullName($this->selectedSlip->handledBy, 'Waiting for cashier') }}
                                    </dd>
                                </div>

                                <div class="flex justify-between gap-4">
                                    <dt class="text-zinc-500">Guest count</dt>
                                    <dd class="font-medium">
                                        {{ $this->totalGuests($this->selectedSlip) }}
                                    </dd>
                                </div>

                                <div class="flex justify-between gap-4">
                                    <dt class="text-zinc-500">Male / Female / Tourist</dt>
                                    <dd class="text-right font-medium">
                                        {{ $this->selectedSlip->no_of_Male }}
                                        /
                                        {{ $this->selectedSlip->no_of_Female }}
                                        /
                                        {{ $this->selectedSlip->no_of_Tourist }}
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold">Entrance fee breakdown</h3>

                            <div class="mt-3 space-y-3">
                                @forelse ($this->selectedSlip->details as $detail)
                                    <div class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-800">
                                        <div class="flex justify-between gap-4">
                                            <div>
                                                <p class="font-medium">
                                                    {{ $detail->entranceFee?->entrance_fee_name ?? 'Entrance fee' }}
                                                    × {{ $detail->guest_quantity }}
                                                </p>

                                                @if ($detail->discount)
                                                    <p class="mt-1 text-xs text-zinc-500">
                                                        {{ $detail->discounted_quantity }}
                                                        discounted via
                                                        {{ $detail->discount->discount_name }}
                                                    </p>
                                                @endif
                                            </div>

                                            <p class="font-medium">
                                                ₱{{ number_format(
                                                    (float) ($detail->entrance_fee_subtotal
                                                        ?? $detail->subtotal
                                                        ?? (
                                                            (float) ($detail->entranceFee?->entrance_fee_price ?? 0)
                                                            * (int) $detail->guest_quantity
                                                        )),
                                                    2,
                                                ) }}
                                            </p>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-zinc-500">
                                        No entrance-fee breakdown is attached to this slip.
                                    </p>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-900">
                            <div class="flex justify-between font-medium">
                                <span>Total price</span>
                                <span>₱{{ number_format((float) $this->selectedSlip->total_price, 2) }}</span>
                            </div>

                            <div class="mt-2 flex justify-between text-lg font-semibold">
                                <span>Amount due</span>
                                <span>₱{{ number_format((float) $this->selectedSlip->amount_due, 2) }}</span>
                            </div>
                        </div>

                        @if ($this->selectedSlip->status !== 'Paid' && (float) $this->selectedSlip->amount_due > 0)
                            <form wire:submit="recordPayment" class="space-y-4">
                                <flux:select
                                    wire:model="modeOfPaymentId"
                                    label="Mode of payment"
                                >
                                    <option value="">Select mode</option>

                                    @foreach ($this->paymentModes as $mode)
                                        <option value="{{ $mode->mode_of_payment_id }}">
                                            {{ $mode->mode_of_payment }}
                                        </option>
                                    @endforeach
                                </flux:select>

                                <flux:input
                                    wire:model="referenceNumber"
                                    label="GCash reference number"
                                    placeholder="Required only for GCash"
                                />

                                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
                                    Entrance slips must be paid in full. The payment amount will be exactly
                                    ₱{{ number_format((float) $this->selectedSlip->amount_due, 2) }}.
                                </div>

                                <flux:button
                                    type="submit"
                                    variant="primary"
                                    class="w-full"
                                >
                                    Record Full Payment
                                </flux:button>
                            </form>
                        @else
                            <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200">
                                This entrance slip is paid and counts as an admitted entry.
                            </div>
                        @endif

                        @if ($this->selectedPayment !== null)
                            <div class="rounded-xl border border-green-200 p-4 dark:border-green-900">
                                <p class="text-sm font-semibold">
                                    Payment {{ $this->selectedPayment->p_ref_no }}
                                </p>

                                <div class="mt-2 flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm">
                                            ₱{{ number_format((float) $this->selectedPayment->amount_paid, 2) }}
                                            via
                                            {{ $this->selectedPayment->modeOfPayment?->mode_of_payment ?? 'Unknown mode' }}
                                        </p>

                                        <flux:badge
                                            color="{{ $this->paymentStatusColor((string) $this->selectedPayment->payment_status) }}"
                                            size="sm"
                                            class="mt-2"
                                        >
                                            {{ $this->selectedPayment->payment_status }}
                                        </flux:badge>
                                    </div>

                                    @if (Route::has('print.payment'))
                                        <flux:button
                                            href="{{ route('print.payment', $this->selectedPayment) }}"
                                            target="_blank"
                                            size="sm"
                                            variant="primary"
                                        >
                                            Print Receipt
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        @elseif ($this->selectedSlip->payments->isNotEmpty())
                            @php($latestPayment = $this->selectedSlip->payments->sortByDesc('payment_id')->first())

                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                                <p class="text-sm font-semibold">
                                    Latest payment: {{ $latestPayment->p_ref_no }}
                                </p>

                                <p class="mt-1 text-sm text-zinc-500">
                                    ₱{{ number_format((float) $latestPayment->amount_paid, 2) }}
                                    via
                                    {{ $latestPayment->modeOfPayment?->mode_of_payment ?? 'Unknown mode' }}
                                </p>

                                @if (Route::has('print.payment'))
                                    <flux:button
                                        href="{{ route('print.payment', $latestPayment) }}"
                                        target="_blank"
                                        size="sm"
                                        variant="ghost"
                                        class="mt-3"
                                    >
                                        Print Receipt
                                    </flux:button>
                                @endif
                            </div>
                        @endif

                        @if (Route::has('print.entrance-slip'))
                            <flux:button
                                href="{{ route('print.entrance-slip', $this->selectedSlip) }}"
                                target="_blank"
                                variant="ghost"
                                class="w-full"
                            >
                                Print Entrance Slip
                            </flux:button>
                        @endif
                    </div>
                @else
                    <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                        Select an entrance slip to review its headcount, fee breakdown, payment status, and receipt.
                    </p>
                @endif
            </flux:card>
        </aside>
    </div>
</div>

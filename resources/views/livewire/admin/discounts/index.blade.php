<?php

use App\Models\Discount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Discount Management - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'timing', except: '')]
    public string $timingFilter = '';

    #[Url(as: 'applies_to', except: '')]
    public string $applicabilityFilter = '';

    #[Url(as: 'sort', except: 'discount_name')]
    public string $sortField = 'discount_name';

    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $editingId = null;
    public string $discountName = '';
    public string $discountPercent = '';
    public string $discountStart = '';
    public string $discountEnd = '';
    public string $status = 'Inactive';

    public bool $appToAdult = false;
    public bool $appToChildren = false;
    public bool $appToScPwd = false;
    public bool $appToCottage = false;
    public bool $appToRoom = false;
    public bool $appToFunctionHall = false;

    #[Computed]
    public function discounts(): LengthAwarePaginator
    {
        $allowedSorts = [
            'discount_name',
            'discount_amount',
            'discount_start',
            'discount_end',
            'status',
        ];

        $sortField = in_array($this->sortField, $allowedSorts, true)
            ? $this->sortField
            : 'discount_name';

        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        $now = now();

        return Discount::query()
            ->withCount([
                'reservationDetails',
                'entranceSlipDetails',
                'bookingDetails',
            ])
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $like = '%'.trim($this->search).'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query->where('discount_name', 'like', $like)
                        ->orWhere('status', 'like', $like)
                        ->orWhere('discount_amount', 'like', $like);
                });
            })
            ->when(
                $this->statusFilter !== '',
                fn (Builder $query) => $query->where(
                    'status',
                    $this->statusFilter,
                ),
            )
            ->when(
                $this->timingFilter === 'current',
                fn (Builder $query) => $query
                    ->where('status', 'Active')
                    ->where(function (Builder $query) use ($now): void {
                        $query->whereNull('discount_start')
                            ->orWhere('discount_start', '<=', $now);
                    })
                    ->where(function (Builder $query) use ($now): void {
                        $query->whereNull('discount_end')
                            ->orWhere('discount_end', '>=', $now);
                    }),
            )
            ->when(
                $this->timingFilter === 'scheduled',
                fn (Builder $query) => $query
                    ->where('status', 'Active')
                    ->whereNotNull('discount_start')
                    ->where('discount_start', '>', $now),
            )
            ->when(
                $this->timingFilter === 'expired',
                fn (Builder $query) => $query
                    ->whereNotNull('discount_end')
                    ->where('discount_end', '<', $now),
            )
            ->when(
                $this->timingFilter === 'open_ended',
                fn (Builder $query) => $query
                    ->whereNull('discount_start')
                    ->whereNull('discount_end'),
            )
            ->when(
                $this->applicabilityFilter !== '',
                function (Builder $query): void {
                    $column = match ($this->applicabilityFilter) {
                        'adult' => 'app_to_adult',
                        'children' => 'app_to_children',
                        'sc_pwd' => 'app_to_SC_PWD',
                        'cottage' => 'app_to_cottage',
                        'room' => 'app_to_room',
                        'function_hall' => 'app_to_function_hall',
                        default => null,
                    };

                    if ($column !== null) {
                        $query->where($column, true);
                    }
                },
            )
            ->orderBy($sortField, $direction)
            ->orderBy('discount_id')
            ->paginate($perPage);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTimingFilter(): void
    {
        $this->resetPage();
    }

    public function updatedApplicabilityFilter(): void
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
        $this->statusFilter = '';
        $this->timingFilter = '';
        $this->applicabilityFilter = '';
        $this->sortField = 'discount_name';
        $this->sortDirection = 'asc';
        $this->perPage = 10;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowed = [
            'discount_name',
            'discount_amount',
            'discount_start',
            'discount_end',
            'status',
        ];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection =
                $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function createNew(): void
    {
        $this->resetForm();
        $this->status = 'Active';
    }

    public function startEditing(int $discountId): void
    {
        $discount = Discount::query()->findOrFail($discountId);

        $this->editingId = (int) $discount->discount_id;
        $this->discountName = (string) $discount->discount_name;
        $this->discountPercent = number_format(
            ((float) $discount->discount_amount) * 100,
            0,
            '.',
            '',
        );
        $this->discountStart =
            $discount->discount_start?->format('Y-m-d\TH:i') ?? '';
        $this->discountEnd =
            $discount->discount_end?->format('Y-m-d\TH:i') ?? '';
        $this->status = (string) $discount->status;

        $this->appToAdult = (bool) $discount->app_to_adult;
        $this->appToChildren = (bool) $discount->app_to_children;
        $this->appToScPwd = (bool) $discount->app_to_SC_PWD;
        $this->appToCottage = (bool) $discount->app_to_cottage;
        $this->appToRoom = (bool) $discount->app_to_room;
        $this->appToFunctionHall =
            (bool) $discount->app_to_function_hall;

        $this->resetValidation();
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->discountName = '';
        $this->discountPercent = '';
        $this->discountStart = '';
        $this->discountEnd = '';
        $this->status = 'Inactive';

        $this->appToAdult = false;
        $this->appToChildren = false;
        $this->appToScPwd = false;
        $this->appToCottage = false;
        $this->appToRoom = false;
        $this->appToFunctionHall = false;

        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'editingId' => [
                'nullable',
                'integer',
                'exists:tbl_discount,discount_id',
            ],
            'discountName' => [
                'required',
                'string',
                'max:50',
            ],
            'discountPercent' => [
                'required',
                'integer',
                'min:1',
                'max:100',
            ],
            'discountStart' => [
                'nullable',
                'date',
            ],
            'discountEnd' => [
                'nullable',
                'date',
                'after_or_equal:discountStart',
            ],
            'status' => [
                'required',
                Rule::in(['Active', 'Inactive']),
            ],
            'appToAdult' => ['boolean'],
            'appToChildren' => ['boolean'],
            'appToScPwd' => ['boolean'],
            'appToCottage' => ['boolean'],
            'appToRoom' => ['boolean'],
            'appToFunctionHall' => ['boolean'],
        ], [
            'discountName.required' =>
                'Discount name is required.',
            'discountName.max' =>
                'Discount name must not exceed 50 characters.',
            'discountPercent.required' =>
                'Discount percentage is required.',
            'discountPercent.integer' =>
                'Discount percentage must be a whole number.',
            'discountPercent.min' =>
                'Discount percentage must be at least 1%.',
            'discountPercent.max' =>
                'Discount percentage cannot exceed 100%.',
            'discountStart.date' =>
                'Discount start must be a valid date and time.',
            'discountEnd.date' =>
                'Discount end must be a valid date and time.',
            'discountEnd.after_or_equal' =>
                'Discount end must be after or equal to discount start.',
            'status.in' =>
                'Status must be Active or Inactive.',
        ]);

        $hasApplicability =
            $this->appToAdult
            || $this->appToChildren
            || $this->appToScPwd
            || $this->appToCottage
            || $this->appToRoom
            || $this->appToFunctionHall;

        if (! $hasApplicability) {
            $this->addError(
                'applicability',
                'Choose at least one category where this discount applies.',
            );

            return;
        }

        $payload = [
            'discount_name' => trim($validated['discountName']),
            'discount_amount' => round(
                ((float) $validated['discountPercent']) / 100,
                2,
            ),
            'app_to_adult' => (bool) $validated['appToAdult'],
            'app_to_children' =>
                (bool) $validated['appToChildren'],
            'app_to_SC_PWD' =>
                (bool) $validated['appToScPwd'],
            'app_to_cottage' =>
                (bool) $validated['appToCottage'],
            'app_to_room' =>
                (bool) $validated['appToRoom'],
            'app_to_function_hall' =>
                (bool) $validated['appToFunctionHall'],
            'discount_start' =>
                filled($validated['discountStart'])
                    ? Carbon::parse(
                        $validated['discountStart'],
                    )->format('Y-m-d H:i:s')
                    : null,
            'discount_end' =>
                filled($validated['discountEnd'])
                    ? Carbon::parse(
                        $validated['discountEnd'],
                    )->format('Y-m-d H:i:s')
                    : null,
            'status' => $validated['status'],
        ];

        if ($this->editingId !== null) {
            Discount::query()
                ->findOrFail($this->editingId)
                ->update($payload);

            session()->flash(
                'success',
                'Discount updated successfully.',
            );
        } else {
            Discount::query()->create($payload);

            session()->flash(
                'success',
                'Discount created successfully.',
            );
        }

        $this->resetForm();
    }

    public function applicabilityLabels(Discount $discount): string
    {
        $labels = [];

        if ($discount->app_to_adult) {
            $labels[] = 'Adults';
        }

        if ($discount->app_to_children) {
            $labels[] = 'Children';
        }

        if ($discount->app_to_SC_PWD) {
            $labels[] = 'Senior/PWD';
        }

        if ($discount->app_to_cottage) {
            $labels[] = 'Cottages';
        }

        if ($discount->app_to_room) {
            $labels[] = 'Rooms';
        }

        if ($discount->app_to_function_hall) {
            $labels[] = 'Function Halls';
        }

        return $labels === [] ? 'None' : implode(', ', $labels);
    }

    public function timingLabel(Discount $discount): string
    {
        if ($discount->status !== 'Active') {
            return 'Inactive';
        }

        $now = now();

        if (
            $discount->discount_start !== null
            && $discount->discount_start->isAfter($now)
        ) {
            return 'Scheduled';
        }

        if (
            $discount->discount_end !== null
            && $discount->discount_end->isBefore($now)
        ) {
            return 'Expired';
        }

        return 'Current';
    }

    public function timingColor(string $timing): string
    {
        return match ($timing) {
            'Current' => 'green',
            'Scheduled' => 'blue',
            'Expired' => 'red',
            'Inactive' => 'zinc',
            default => 'zinc',
        };
    }

    public function sortIcon(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function usageCount(Discount $discount): int
    {
        return (int) $discount->reservation_details_count
            + (int) $discount->entrance_slip_details_count
            + (int) $discount->booking_details_count;
    }

    public function usageSummary(Discount $discount): string
    {
        return sprintf(
            'Reservations %d · Entrance slips %d · Bookings %d',
            (int) $discount->reservation_details_count,
            (int) $discount->entrance_slip_details_count,
            (int) $discount->booking_details_count,
        );
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">
                Discount Management
            </h1>

            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Create and update discounts for entrance fees and facility bookings.
            </p>
        </div>

        @if (Route::has('admin.dashboard'))
            <flux:button
                href="{{ route('admin.dashboard') }}"
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

    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
        Existing transaction records retain their saved discount snapshots.
        Changing a discount affects future calculations, not completed historical transactions.
    </div>

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,2fr)_minmax(23rem,1fr)]">
        <section class="min-w-0">
            <flux:card class="overflow-hidden p-0">
                <div class="border-b border-zinc-200 p-5 dark:border-zinc-800">
                    <div>
                        <h2 class="font-semibold">Discount list</h2>

                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Search, filter, sort, and paginate discount master records.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                        <flux:input
                            wire:model.live.debounce.300ms="search"
                            label="Search"
                            placeholder="Name, percentage, or status"
                            clearable
                            class="xl:col-span-2"
                        />

                        <flux:select
                            wire:model.live="statusFilter"
                            label="Admin status"
                        >
                            <option value="">All statuses</option>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </flux:select>

                        <flux:select
                            wire:model.live="timingFilter"
                            label="Effective timing"
                        >
                            <option value="">All timing states</option>
                            <option value="current">Current</option>
                            <option value="scheduled">Scheduled</option>
                            <option value="expired">Expired</option>
                            <option value="open_ended">Open-ended period</option>
                        </flux:select>

                        <flux:select
                            wire:model.live="applicabilityFilter"
                            label="Applies to"
                        >
                            <option value="">All categories</option>
                            <option value="adult">Adults</option>
                            <option value="children">Children</option>
                            <option value="sc_pwd">Senior/PWD</option>
                            <option value="cottage">Cottages</option>
                            <option value="room">Rooms</option>
                            <option value="function_hall">Function Halls</option>
                        </flux:select>

                        <flux:select
                            wire:model.live="perPage"
                            label="Rows"
                        >
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </flux:select>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <flux:button
                            type="button"
                            wire:click="clearFilters"
                            variant="ghost"
                        >
                            Clear Filters
                        </flux:button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[86rem] text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                            <tr>
                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('discount_name')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Name {{ $this->sortIcon('discount_name') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('discount_amount')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Discount {{ $this->sortIcon('discount_amount') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">Applies to</th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('discount_start')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Start {{ $this->sortIcon('discount_start') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('discount_end')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        End {{ $this->sortIcon('discount_end') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('status')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Admin Status {{ $this->sortIcon('status') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">Effective State</th>
                                <th class="px-5 py-3">Usage</th>
                                <th class="px-5 py-3 text-right">Action</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->discounts as $discount)
                                @php($timing = $this->timingLabel($discount))

                                <tr wire:key="discount-row-{{ $discount->discount_id }}">
                                    <td class="px-5 py-4 font-medium">
                                        {{ $discount->discount_name }}
                                    </td>

                                    <td class="px-5 py-4 font-semibold">
                                        {{ number_format((float) $discount->discount_amount * 100, 0) }}%
                                    </td>

                                    <td class="max-w-sm px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                        {{ $this->applicabilityLabels($discount) }}
                                    </td>

                                    <td class="px-5 py-4">
                                        {{ $discount->discount_start?->format('M d, Y h:i A') ?? 'No start limit' }}
                                    </td>

                                    <td class="px-5 py-4">
                                        {{ $discount->discount_end?->format('M d, Y h:i A') ?? 'No end limit' }}
                                    </td>

                                    <td class="px-5 py-4">
                                        <flux:badge
                                            color="{{ $discount->status === 'Active' ? 'green' : 'zinc' }}"
                                            size="sm"
                                        >
                                            {{ $discount->status }}
                                        </flux:badge>
                                    </td>

                                    <td class="px-5 py-4">
                                        <flux:badge
                                            color="{{ $this->timingColor($timing) }}"
                                            size="sm"
                                        >
                                            {{ $timing }}
                                        </flux:badge>
                                    </td>

                                    <td class="max-w-xs px-5 py-4">
                                        <p class="font-medium">
                                            {{ $this->usageCount($discount) }} transaction link(s)
                                        </p>

                                        <p class="mt-1 text-xs leading-5 text-zinc-500">
                                            {{ $this->usageSummary($discount) }}
                                        </p>
                                    </td>

                                    <td class="px-5 py-4 text-right">
                                        <flux:button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            wire:click="startEditing({{ $discount->discount_id }})"
                                        >
                                            Edit
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-5 py-12 text-center text-zinc-500">
                                        No discount matches the selected filters.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                    <p class="text-sm text-zinc-500">
                        Showing
                        {{ $this->discounts->firstItem() ?? 0 }}
                        to
                        {{ $this->discounts->lastItem() ?? 0 }}
                        of
                        {{ $this->discounts->total() }}
                        discounts
                    </p>

                    {{ $this->discounts->links() }}
                </div>
            </flux:card>
        </section>

        <aside>
            <flux:card>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">
                            {{ $editingId !== null
                                ? 'Edit discount'
                                : 'Create discount' }}
                        </h2>

                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Enter whole-number percentages. Example: type 10 for 10%.
                        </p>
                    </div>

                    <flux:button
                        type="button"
                        size="sm"
                        variant="ghost"
                        wire:click="createNew"
                    >
                        New
                    </flux:button>
                </div>

                <form wire:submit="save" class="mt-5 space-y-4">
                    <flux:input
                        wire:model="discountName"
                        label="Discount name"
                        placeholder="Christmas Promo"
                    />

                    <flux:input
                        wire:model="discountPercent"
                        type="number"
                        step="1"
                        min="1"
                        max="100"
                        label="Discount percentage"
                        placeholder="10"
                    />

                    <div>
                        <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Start date and time
                        </label>

                        <input
                            wire:model="discountStart"
                            type="datetime-local"
                            class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                        />

                        @error('discountStart')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            End date and time
                        </label>

                        <input
                            wire:model="discountEnd"
                            type="datetime-local"
                            class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                        />

                        @error('discountEnd')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <flux:select wire:model="status" label="Admin status">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </flux:select>

                    <div>
                        <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                            Applies to
                        </p>

                        <div class="grid gap-2 text-sm text-zinc-700 dark:text-zinc-300 sm:grid-cols-2">
                            <label class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToAdult" type="checkbox" class="rounded border-zinc-300" />
                                Adults
                            </label>

                            <label class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToChildren" type="checkbox" class="rounded border-zinc-300" />
                                Children
                            </label>

                            <label class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToScPwd" type="checkbox" class="rounded border-zinc-300" />
                                Senior/PWD
                            </label>

                            <label class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToCottage" type="checkbox" class="rounded border-zinc-300" />
                                Cottages
                            </label>

                            <label class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToRoom" type="checkbox" class="rounded border-zinc-300" />
                                Rooms
                            </label>

                            <label class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToFunctionHall" type="checkbox" class="rounded border-zinc-300" />
                                Function Halls
                            </label>
                        </div>

                        @error('applicability')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button
                            type="button"
                            variant="ghost"
                            wire:click="resetForm"
                        >
                            Clear
                        </flux:button>

                        <flux:button type="submit" variant="primary">
                            {{ $editingId !== null
                                ? 'Save Changes'
                                : 'Create Discount' }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        </aside>
    </div>
</div>

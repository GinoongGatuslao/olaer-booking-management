<?php

use App\Models\EntranceFee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Entrance Fee Management - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'usage', except: '')]
    public string $usageFilter = '';

    #[Url(as: 'sort', except: 'entrance_fee_name')]
    public string $sortField = 'entrance_fee_name';

    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $editingId = null;
    public string $entranceFeeName = '';
    public string $entranceFeePrice = '';

    #[Computed]
    public function entranceFees(): LengthAwarePaginator
    {
        $allowedSorts = [
            'entrance_fee_name',
            'entrance_fee_price',
            'usage_count',
        ];

        $sortField = in_array(
            $this->sortField,
            $allowedSorts,
            true,
        )
            ? $this->sortField
            : 'entrance_fee_name';

        $direction =
            $this->sortDirection === 'desc'
                ? 'desc'
                : 'asc';

        $perPage = in_array(
            $this->perPage,
            [10, 25, 50, 100],
            true,
        )
            ? $this->perPage
            : 10;

        $query = EntranceFee::query()
            ->select('tbl_entrance_fee.*')
            ->selectSub(
                function ($query): void {
                    $query
                        ->from('tbl_entrance_slip_details')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn(
                            'tbl_entrance_slip_details.entrance_fee_id',
                            'tbl_entrance_fee.entrance_fee_id',
                        );
                },
                'usage_count',
            )
            ->when(
                trim($this->search) !== '',
                function (Builder $query): void {
                    $like = '%'.trim($this->search).'%';

                    $query->where(
                        function (Builder $query) use ($like): void {
                            $query
                                ->where(
                                    'entrance_fee_name',
                                    'like',
                                    $like,
                                )
                                ->orWhere(
                                    'entrance_fee_price',
                                    'like',
                                    $like,
                                );
                        },
                    );
                },
            )
            ->when(
                $this->usageFilter === 'used',
                fn (Builder $query) =>
                    $query->having('usage_count', '>', 0),
            )
            ->when(
                $this->usageFilter === 'unused',
                fn (Builder $query) =>
                    $query->having('usage_count', '=', 0),
            );

        match ($sortField) {
            'entrance_fee_price' =>
                $query->orderBy(
                    'tbl_entrance_fee.entrance_fee_price',
                    $direction,
                ),
            'usage_count' =>
                $query->orderBy(
                    'usage_count',
                    $direction,
                ),
            default =>
                $query->orderBy(
                    'tbl_entrance_fee.entrance_fee_name',
                    $direction,
                ),
        };

        return $query
            ->orderBy(
                'tbl_entrance_fee.entrance_fee_id',
            )
            ->paginate($perPage);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedUsageFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array(
            $this->perPage,
            [10, 25, 50, 100],
            true,
        )) {
            $this->perPage = 10;
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->usageFilter = '';
        $this->sortField = 'entrance_fee_name';
        $this->sortDirection = 'asc';
        $this->perPage = 10;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        $allowedSorts = [
            'entrance_fee_name',
            'entrance_fee_price',
            'usage_count',
        ];

        if (! in_array($field, $allowedSorts, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDirection =
                $this->sortDirection === 'asc'
                    ? 'desc'
                    : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function startEditing(
        int $entranceFeeId,
    ): void {
        $entranceFee = EntranceFee::query()
            ->findOrFail($entranceFeeId);

        $this->editingId =
            (int) $entranceFee->entrance_fee_id;
        $this->entranceFeeName =
            (string) $entranceFee->entrance_fee_name;
        $this->entranceFeePrice = number_format(
            (float) $entranceFee->entrance_fee_price,
            2,
            '.',
            '',
        );

        $this->resetValidation();
    }

    public function cancelEditing(): void
    {
        $this->editingId = null;
        $this->entranceFeeName = '';
        $this->entranceFeePrice = '';

        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'editingId' => [
                'required',
                'integer',
                'exists:tbl_entrance_fee,entrance_fee_id',
            ],
            'entranceFeePrice' => [
                'required',
                'numeric',
                'min:0',
                'max:999999.99',
            ],
        ], [
            'editingId.required' =>
                'Choose an entrance fee category first.',
            'entranceFeePrice.required' =>
                'Entrance fee amount is required.',
            'entranceFeePrice.numeric' =>
                'Entrance fee amount must be a valid number.',
            'entranceFeePrice.min' =>
                'Entrance fee amount cannot be negative.',
            'entranceFeePrice.max' =>
                'Entrance fee amount is too high.',
        ]);

        $entranceFee = EntranceFee::query()
            ->findOrFail(
                (int) $validated['editingId'],
            );

        $entranceFee->update([
            'entrance_fee_price' => round(
                (float) $validated['entranceFeePrice'],
                2,
            ),
        ]);

        session()->flash(
            'success',
            $entranceFee->entrance_fee_name
                .' entrance fee updated to ₱'
                .number_format(
                    (float) $validated['entranceFeePrice'],
                    2,
                )
                .'.',
        );

        $this->cancelEditing();

        unset($this->entranceFees);
    }

    public function sortIndicator(
        string $field,
    ): string {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc'
            ? '↑'
            : '↓';
    }

    public function categoryDescription(
        string $name,
    ): string {
        return match ($name) {
            'Adult' =>
                'Regular entrance rate for adult guests.',
            'Children' =>
                'Entrance rate for child guests.',
            'Senior Citizen / PWD' =>
                'Base entrance rate before any applicable Senior/PWD discount.',
            default =>
                'Entrance category used by Security and Cashier workflows.',
        };
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">
                Entrance Fee Management
            </h1>

            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Maintain the canonical Adult, Children, and Senior Citizen/PWD entrance rates.
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
        Category names are intentionally read-only because the entrance-slip calculator maps these exact canonical names.
        Updating a rate affects future entrance slips only; completed slips retain their recorded totals.
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(22rem,1fr)]">
        <section class="min-w-0">
            <flux:card class="overflow-hidden p-0">
                <div class="border-b border-zinc-200 p-5 dark:border-zinc-800">
                    <div>
                        <h2 class="font-semibold">
                            Current entrance fees
                        </h2>

                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            These rates are used when Security creates entrance slips and Cashier collects payment.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <flux:input
                            wire:model.live.debounce.300ms="search"
                            label="Search"
                            placeholder="Category or amount"
                            clearable
                            class="lg:col-span-2"
                        />

                        <flux:select
                            wire:model.live="usageFilter"
                            label="Historical usage"
                        >
                            <option value="">All categories</option>
                            <option value="used">Used in entrance slips</option>
                            <option value="unused">Not yet used</option>
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
                    <table class="w-full min-w-[48rem] text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                            <tr>
                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('entrance_fee_name')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Category
                                        {{ $this->sortIndicator('entrance_fee_name') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    Description
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('entrance_fee_price')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Current Rate
                                        {{ $this->sortIndicator('entrance_fee_price') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('usage_count')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Usage
                                        {{ $this->sortIndicator('usage_count') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3 text-right">
                                    Action
                                </th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->entranceFees as $entranceFee)
                                <tr wire:key="entrance-fee-{{ $entranceFee->entrance_fee_id }}">
                                    <td class="px-5 py-4 font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $entranceFee->entrance_fee_name }}
                                    </td>

                                    <td class="max-w-sm px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                        {{ $this->categoryDescription(
                                            (string) $entranceFee->entrance_fee_name
                                        ) }}
                                    </td>

                                    <td class="px-5 py-4 text-lg font-semibold">
                                        ₱{{ number_format(
                                            (float) $entranceFee->entrance_fee_price,
                                            2,
                                        ) }}
                                    </td>

                                    <td class="px-5 py-4">
                                        <p class="font-medium">
                                            {{ (int) $entranceFee->usage_count }}
                                            line item(s)
                                        </p>

                                        <p class="mt-1 text-xs text-zinc-500">
                                            Historical entrance-slip detail references
                                        </p>
                                    </td>

                                    <td class="px-5 py-4 text-right">
                                        <flux:button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            wire:click="startEditing({{ $entranceFee->entrance_fee_id }})"
                                        >
                                            Edit Rate
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-12 text-center text-zinc-500">
                                        No entrance fee category matches the selected filters.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                    <p class="text-sm text-zinc-500">
                        Showing
                        {{ $this->entranceFees->firstItem() ?? 0 }}
                        to
                        {{ $this->entranceFees->lastItem() ?? 0 }}
                        of
                        {{ $this->entranceFees->total() }}
                        entrance fee categories
                    </p>

                    {{ $this->entranceFees->links() }}
                </div>
            </flux:card>
        </section>

        <aside>
            <flux:card>
                <h2 class="font-semibold">
                    Edit entrance fee
                </h2>

                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Select a category, then update only its current rate.
                </p>

                @if ($editingId !== null)
                    <form wire:submit="save" class="mt-5 space-y-4">
                        <flux:input
                            wire:model="entranceFeeName"
                            label="Canonical category"
                            readonly
                        />

                        <flux:input
                            wire:model="entranceFeePrice"
                            type="number"
                            step="0.01"
                            min="0"
                            label="Current entrance rate"
                            placeholder="0.00"
                        />

                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                            Discounts are calculated separately. For example, this field stores the base Senior Citizen/PWD rate before an applicable discount is calculated.
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <flux:button
                                type="submit"
                                variant="primary"
                            >
                                Save Rate
                            </flux:button>

                            <flux:button
                                type="button"
                                variant="ghost"
                                wire:click="cancelEditing"
                            >
                                Cancel
                            </flux:button>
                        </div>
                    </form>
                @else
                    <div class="mt-5 rounded-xl border border-dashed border-zinc-300 p-5 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        Select an entrance fee category from the table.
                    </div>
                @endif
            </flux:card>
        </aside>
    </div>
</div>

<?php

use App\Models\Discount;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts.app');
title('Discount Management - Olaer Spring Resort');

state([
    'search' => '',
    'sortField' => 'discount_name',
    'sortDirection' => 'asc',

    'editingId' => null,
    'discountName' => '',
    'discountPercent' => '',
    'discountStart' => '',
    'discountEnd' => '',
    'status' => 'Inactive',

    'appToAdult' => false,
    'appToChildren' => false,
    'appToScPwd' => false,
    'appToCottage' => false,
    'appToRoom' => false,
    'appToFunctionHall' => false,
]);

$discounts = computed(function () {
    $allowedSorts = ['discount_name', 'discount_amount', 'discount_start', 'discount_end', 'status'];

    $sortField = in_array($this->sortField, $allowedSorts, true) ? $this->sortField : 'discount_name';

    $sortDirection = $this->sortDirection === 'desc' ? 'desc' : 'asc';

    return Discount::query()
        ->when(trim($this->search) !== '', function ($query) {
            $search = '%' . trim($this->search) . '%';

            $query->where(function ($query) use ($search) {
                $query->where('discount_name', 'like', $search)->orWhere('status', 'like', $search);
            });
        })
        ->orderBy($sortField, $sortDirection)
        ->get();
});

$sortBy = function (string $field): void {
    $allowedSorts = ['discount_name', 'discount_amount', 'discount_start', 'discount_end', 'status'];

    if (!in_array($field, $allowedSorts, true)) {
        return;
    }

    if ($this->sortField === $field) {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        return;
    }

    $this->sortField = $field;
    $this->sortDirection = 'asc';
};

$createNew = function (): void {
    $this->resetForm();
    $this->status = 'Active';
};

$startEditing = function (int $discountId): void {
    $discount = Discount::query()->findOrFail($discountId);

    $this->editingId = $discount->discount_id;
    $this->discountName = $discount->discount_name;
    $this->discountPercent = number_format(((float) $discount->discount_amount) * 100, 0, '.', '');
    $this->discountStart = $discount->discount_start?->format('Y-m-d\TH:i') ?? '';
    $this->discountEnd = $discount->discount_end?->format('Y-m-d\TH:i') ?? '';
    $this->status = $discount->status;

    $this->appToAdult = (bool) $discount->app_to_adult;
    $this->appToChildren = (bool) $discount->app_to_children;
    $this->appToScPwd = (bool) $discount->app_to_SC_PWD;
    $this->appToCottage = (bool) $discount->app_to_cottage;
    $this->appToRoom = (bool) $discount->app_to_room;
    $this->appToFunctionHall = (bool) $discount->app_to_function_hall;

    $this->resetValidation();
};

$resetForm = function (): void {
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
};

$save = function (): void {
    $validated = $this->validate(
        [
            'editingId' => ['nullable', 'integer', 'exists:tbl_discount,discount_id'],
            'discountName' => ['required', 'string', 'max:50'],
            'discountPercent' => ['required', 'integer', 'min:1', 'max:100'],
            'discountStart' => ['nullable', 'date'],
            'discountEnd' => ['nullable', 'date', 'after_or_equal:discountStart'],
            'status' => ['required', Rule::in(['Active', 'Inactive'])],
            'appToAdult' => ['boolean'],
            'appToChildren' => ['boolean'],
            'appToScPwd' => ['boolean'],
            'appToCottage' => ['boolean'],
            'appToRoom' => ['boolean'],
            'appToFunctionHall' => ['boolean'],
        ],
        [
            'discountName.required' => 'Discount name is required.',
            'discountName.max' => 'Discount name must not exceed 50 characters.',
            'discountPercent.required' => 'Discount percentage is required.',
            'discountPercent.integer' => 'Discount percentage must be a whole number.',
            'discountPercent.min' => 'Discount percentage must be at least 1%.',
            'discountPercent.max' => 'Discount percentage cannot exceed 100%.',
            'discountStart.date' => 'Discount start must be a valid date and time.',
            'discountEnd.date' => 'Discount end must be a valid date and time.',
            'discountEnd.after_or_equal' => 'Discount end must be after or equal to discount start.',
            'status.in' => 'Status must be Active or Inactive.',
        ],
    );

    $hasApplicability = $this->appToAdult || $this->appToChildren || $this->appToScPwd || $this->appToCottage || $this->appToRoom || $this->appToFunctionHall;

    if (!$hasApplicability) {
        $this->addError('applicability', 'Choose at least one category where this discount applies.');
        return;
    }

    $payload = [
        'discount_name' => trim($validated['discountName']),
        // Store as decimal fraction to match the capstone examples:
        // 10% becomes 0.10, 50% becomes 0.50.
        'discount_amount' => round(((float) $validated['discountPercent']) / 100, 2),
        'app_to_adult' => (bool) $validated['appToAdult'],
        'app_to_children' => (bool) $validated['appToChildren'],
        'app_to_SC_PWD' => (bool) $validated['appToScPwd'],
        'app_to_cottage' => (bool) $validated['appToCottage'],
        'app_to_room' => (bool) $validated['appToRoom'],
        'app_to_function_hall' => (bool) $validated['appToFunctionHall'],
        'discount_start' => filled($validated['discountStart']) ? Carbon::parse($validated['discountStart'])->format('Y-m-d H:i:s') : null,
        'discount_end' => filled($validated['discountEnd']) ? Carbon::parse($validated['discountEnd'])->format('Y-m-d H:i:s') : null,
        'status' => $validated['status'],
    ];

    if ($this->editingId) {
        Discount::query()->findOrFail($this->editingId)->update($payload);
        session()->flash('success', 'Discount updated successfully.');
    } else {
        Discount::query()->create($payload);
        session()->flash('success', 'Discount created successfully.');
    }

    $this->resetForm();
};

$getApplicabilityLabels = function (Discount $discount): string {
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
};

$getSortIcon = function (string $field): string {
    if ($this->sortField !== $field) {
        return '↕';
    }

    return $this->sortDirection === 'asc' ? '↑' : '↓';
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Discount Management</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Create and update discounts for entrance fees and facility bookings.
            </p>
        </div>

        <a href="{{ route('admin.dashboard') }}"
            class="text-sm font-medium text-zinc-600 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white">
            Back to dashboard
        </a>
    </div>

    @if (session('success'))
        <div
            class="rounded-xl border border-green-300 bg-green-100 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="xl:col-span-2">
            <div
                class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div
                    class="flex flex-col gap-4 border-b border-zinc-200 px-5 py-4 dark:border-zinc-800 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="font-semibold">Discount list</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Search and sort discounts. Click Edit to modify an existing discount.
                        </p>
                    </div>

                    <div class="w-full sm:w-72">
                        <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="Name or status"
                            clearable />
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-800">
                        <thead
                            class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-950/60 dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('discount_name')"
                                        class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Name <span>{{ $this->getSortIcon('discount_name') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('discount_amount')"
                                        class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Discount <span>{{ $this->getSortIcon('discount_amount') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">Applies to</th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('discount_start')"
                                        class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Start <span>{{ $this->getSortIcon('discount_start') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('discount_end')"
                                        class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        End <span>{{ $this->getSortIcon('discount_end') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('status')"
                                        class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Status <span>{{ $this->getSortIcon('status') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 text-right font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->discounts as $discount)
                                <tr wire:key="discount-{{ $discount->discount_id }}">
                                    <td class="px-5 py-4 font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $discount->discount_name }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ number_format(((float) $discount->discount_amount) * 100, 0) }}%
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $this->getApplicabilityLabels($discount) }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $discount->discount_start?->format('M d, Y h:i A') ?? 'No start' }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $discount->discount_end?->format('M d, Y h:i A') ?? 'No end' }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <span
                                            class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $discount->status === 'Active' ? 'bg-green-50 text-green-700 ring-1 ring-green-600/20 dark:bg-green-950/40 dark:text-green-300' : 'bg-zinc-100 text-zinc-700 ring-1 ring-zinc-600/10 dark:bg-zinc-800 dark:text-zinc-300' }}">
                                            {{ $discount->status }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <flux:button type="button" size="sm" variant="subtle"
                                            wire:click="startEditing({{ $discount->discount_id }})">
                                            Edit
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-5 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                        No discounts found. Create your first discount using the form.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section>
            <div
                class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">{{ $editingId ? 'Edit discount' : 'Create discount' }}</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Enter whole-number percentages. Example: type 10 for 10%.
                        </p>
                    </div>

                    <flux:button type="button" size="sm" variant="subtle" wire:click="createNew">
                        New
                    </flux:button>
                </div>

                <form wire:submit="save" class="mt-5 space-y-4">
                    <flux:input wire:model="discountName" label="Discount name" placeholder="Christmas Promo" />
                    @error('discountName')
                        <p class="-mt-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <flux:input wire:model="discountPercent" type="number" step="1" min="1" max="100"
                        label="Discount percentage" placeholder="10" />
                    @error('discountPercent')
                        <p class="-mt-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Start date
                                and time</label>
                            <input wire:model="discountStart" type="datetime-local"
                                class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white" />
                            @error('discountStart')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">End date and
                                time</label>
                            <input wire:model="discountEnd" type="datetime-local"
                                class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white" />
                            @error('discountEnd')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Status</label>
                        <select wire:model="status"
                            class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                        @error('status')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">Applies to</p>
                        <div class="grid gap-2 text-sm text-zinc-700 dark:text-zinc-300 sm:grid-cols-2">
                            <label
                                class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToAdult" type="checkbox"
                                    class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900 dark:border-zinc-700" />
                                Adults
                            </label>

                            <label
                                class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToChildren" type="checkbox"
                                    class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900 dark:border-zinc-700" />
                                Children
                            </label>

                            <label
                                class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToScPwd" type="checkbox"
                                    class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900 dark:border-zinc-700" />
                                Senior/PWD
                            </label>

                            <label
                                class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToCottage" type="checkbox"
                                    class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900 dark:border-zinc-700" />
                                Cottages
                            </label>

                            <label
                                class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToRoom" type="checkbox"
                                    class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900 dark:border-zinc-700" />
                                Rooms
                            </label>

                            <label
                                class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-800">
                                <input wire:model="appToFunctionHall" type="checkbox"
                                    class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900 dark:border-zinc-700" />
                                Function Halls
                            </label>
                        </div>
                        @error('applicability')
                            <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex gap-2 pt-2">
                        <flux:button type="submit" variant="primary">
                            {{ $editingId ? 'Save changes' : 'Create discount' }}
                        </flux:button>

                        <flux:button type="button" variant="subtle" wire:click="resetForm">
                            Cancel
                        </flux:button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>

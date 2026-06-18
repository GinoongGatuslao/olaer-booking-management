<?php

use App\Models\EntranceFee;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts.app');
title('Entrance Fee Management - Olaer Spring Resort');

state([
    'editingId' => null,
    'entranceFeeName' => '',
    'entranceFeePrice' => '',
]);

$entranceFees = computed(function () {
    return EntranceFee::query()
        ->orderBy('entrance_fee_name')
        ->get();
});

$startEditing = function (int $entranceFeeId): void {
    $entranceFee = EntranceFee::query()->findOrFail($entranceFeeId);

    $this->editingId = $entranceFee->entrance_fee_id;
    $this->entranceFeeName = $entranceFee->entrance_fee_name;
    $this->entranceFeePrice = number_format((float) $entranceFee->entrance_fee_price, 2, '.', '');

    $this->resetValidation();
};

$cancelEditing = function (): void {
    $this->editingId = null;
    $this->entranceFeeName = '';
    $this->entranceFeePrice = '';

    $this->resetValidation();
};

$save = function (): void {
    $validated = $this->validate([
        'editingId' => ['required', 'integer', 'exists:tbl_entrance_fee,entrance_fee_id'],
        'entranceFeePrice' => ['required', 'numeric', 'min:0', 'max:999999.99'],
    ], [
        'editingId.required' => 'Please choose an entrance fee category to edit.',
        'entranceFeePrice.required' => 'The entrance fee amount is required.',
        'entranceFeePrice.numeric' => 'The entrance fee amount must be a valid number.',
        'entranceFeePrice.min' => 'The entrance fee amount cannot be negative.',
        'entranceFeePrice.max' => 'The entrance fee amount is too high.',
    ]);

    $entranceFee = EntranceFee::query()->findOrFail($validated['editingId']);

    $entranceFee->update([
        'entrance_fee_price' => $validated['entranceFeePrice'],
    ]);

    session()->flash('success', 'Entrance fee updated successfully.');

    $this->cancelEditing();
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Entrance Fee Management</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Update entrance fee rates for adults, children, and senior citizens/PWDs.
            </p>
        </div>

        <a href="{{ route('admin.dashboard') }}" class="text-sm font-medium text-zinc-600 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white">
            Back to dashboard
        </a>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900/60 dark:bg-green-950/40 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="lg:col-span-2">
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <h2 class="font-semibold">Current entrance fees</h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        These rates are used later when the cashier processes paid entrance slips.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-950/60 dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3 font-semibold">Category</th>
                                <th class="px-5 py-3 font-semibold">Amount</th>
                                <th class="px-5 py-3 text-right font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->entranceFees as $entranceFee)
                                <tr wire:key="entrance-fee-{{ $entranceFee->entrance_fee_id }}">
                                    <td class="px-5 py-4 font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $entranceFee->entrance_fee_name }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        ₱{{ number_format((float) $entranceFee->entrance_fee_price, 2) }}
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <flux:button
                                            type="button"
                                            size="sm"
                                            variant="subtle"
                                            wire:click="startEditing({{ $entranceFee->entrance_fee_id }})"
                                        >
                                            Edit
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-5 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                        No entrance fee categories found. Run the seeders first.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="font-semibold">Edit entrance fee</h2>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Select a category from the table, then update its amount.
                </p>

                @if ($editingId)
                    <form wire:submit="save" class="mt-5 space-y-4">
                        <flux:input
                            wire:model="entranceFeeName"
                            label="Category"
                            readonly
                        />

                        <div>
                            <flux:input
                                wire:model="entranceFeePrice"
                                type="number"
                                step="0.01"
                                min="0"
                                label="Amount"
                                placeholder="0.00"
                            />
                            @error('entranceFeePrice')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex gap-2">
                            <flux:button type="submit" variant="primary">
                                Save changes
                            </flux:button>

                            <flux:button type="button" variant="subtle" wire:click="cancelEditing">
                                Cancel
                            </flux:button>
                        </div>
                    </form>
                @else
                    <div class="mt-5 rounded-xl border border-dashed border-zinc-300 p-5 text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                        No category selected.
                    </div>
                @endif
            </div>
        </section>
    </div>
</div>

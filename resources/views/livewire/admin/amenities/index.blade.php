<?php

use App\Models\Amenity;
use App\Models\AmenityName;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, state, title, updated, usesPagination};

layout('layouts.app');
title('Amenity Management - Olaer Spring Resort');
usesPagination();

state([
    'search' => '',
    'typeFilter' => '',
    'sortField' => 'amenity_name',
    'sortDirection' => 'asc',

    'editingId' => null,
    'amenityName' => '',
    'amenityDescription' => '',
    'amenityType' => 'Rentable',
    'amenityPrice' => '',
]);

$amenities = computed(function () {
    $search = trim($this->search);
    $sortDirection = $this->sortDirection === 'desc' ? 'desc' : 'asc';

    $query = Amenity::query()
        ->with('amenityName')
        ->withCount([
            'facilityAmenities',
            'amenityRequestDetails',
            'fines',
        ])
        ->when($search !== '', function ($query) use ($search) {
            $like = '%' . $search . '%';

            $query->where(function ($query) use ($like) {
                $query->where('amenity_description', 'like', $like)
                    ->orWhere('amenity_type', 'like', $like)
                    ->orWhere('amenity_price', 'like', $like)
                    ->orWhereHas('amenityName', function ($query) use ($like) {
                        $query->where('amenity_name', 'like', $like);
                    });
            });
        })
        ->when($this->typeFilter !== '', function ($query) {
            $query->where('amenity_type', $this->typeFilter);
        });

    match ($this->sortField) {
        'amenity_description', 'amenity_type', 'amenity_price' => $query->orderBy($this->sortField, $sortDirection),
        'usage' => $query->orderByRaw('(facility_amenities_count + amenity_request_details_count + fines_count) ' . $sortDirection),
        default => $query->orderBy(
            AmenityName::query()
                ->select('amenity_name')
                ->whereColumn('tbl_amenity_name.amenity_name_id', 'tbl_amenity.amenity_name_id'),
            $sortDirection,
        ),
    };

    return $query
        ->orderBy('amenity_id')
        ->paginate(5);
});

$createNew = function (): void {
    $this->resetForm();
};

$startEditing = function (int $amenityId): void {
    $amenity = Amenity::query()
        ->with('amenityName')
        ->findOrFail($amenityId);

    $this->editingId = $amenity->amenity_id;
    $this->amenityName = $amenity->amenityName?->amenity_name ?? '';
    $this->amenityDescription = $amenity->amenity_description;
    $this->amenityType = $amenity->amenity_type;
    $this->amenityPrice = number_format((float) $amenity->amenity_price, 2, '.', '');

    $this->resetValidation();
};

$resetForm = function (): void {
    $this->editingId = null;
    $this->amenityName = '';
    $this->amenityDescription = '';
    $this->amenityType = 'Rentable';
    $this->amenityPrice = '';

    $this->resetValidation();
};

$sortBy = function (string $field): void {
    $allowedSorts = [
        'amenity_name',
        'amenity_description',
        'amenity_type',
        'amenity_price',
        'usage',
    ];

    if (! in_array($field, $allowedSorts, true)) {
        return;
    }

    if ($this->sortField === $field) {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->resetPage();
        return;
    }

    $this->sortField = $field;
    $this->sortDirection = 'asc';
    $this->resetPage();
};

updated([
    'search' => function (): void {
        $this->resetPage();
    },
    'typeFilter' => function (): void {
        $this->resetPage();
    },
]);

$save = function (): void {
    $validated = $this->validate([
        'editingId' => ['nullable', 'integer', 'exists:tbl_amenity,amenity_id'],
        'amenityName' => ['required', 'string', 'max:50'],
        'amenityDescription' => ['required', 'string', 'max:50'],
        'amenityType' => ['required', Rule::in(['Rentable', 'Inclusive'])],
        'amenityPrice' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
    ], [
        'amenityName.required' => 'Amenity name is required.',
        'amenityName.max' => 'Amenity name must not exceed 50 characters.',
        'amenityDescription.required' => 'Amenity description is required.',
        'amenityDescription.max' => 'Amenity description must not exceed 50 characters.',
        'amenityType.in' => 'Amenity type must be Rentable or Inclusive.',
        'amenityPrice.numeric' => 'Amenity price must be a valid amount.',
    ]);

    $name = trim(preg_replace('/\s+/', ' ', $validated['amenityName']));
    $description = trim(preg_replace('/\s+/', ' ', $validated['amenityDescription']));
    $type = $validated['amenityType'];
    $price = $type === 'Inclusive' ? 0.00 : round((float) ($validated['amenityPrice'] ?? 0), 2);

    if ($type === 'Rentable' && $price <= 0) {
        $this->addError('amenityPrice', 'Rentable amenities must have a price greater than zero because they are billed to guests.');
        return;
    }

    $amenityName = AmenityName::query()
        ->whereRaw('LOWER(amenity_name) = ?', [mb_strtolower($name)])
        ->first();

    if (! $amenityName) {
        $amenityName = AmenityName::query()->create([
            'amenity_name' => $name,
        ]);
    }

    $duplicate = Amenity::query()
        ->where('amenity_name_id', $amenityName->amenity_name_id)
        ->where('amenity_description', $description)
        ->where('amenity_type', $type)
        ->when($this->editingId, function ($query) {
            $query->where('amenity_id', '!=', $this->editingId);
        })
        ->exists();

    if ($duplicate) {
        $this->addError('amenityName', 'This amenity already exists with the same name, description, and type.');
        return;
    }

    $payload = [
        'amenity_name_id' => $amenityName->amenity_name_id,
        'amenity_description' => $description,
        'amenity_type' => $type,
        'amenity_price' => $price,
    ];

    if ($this->editingId) {
        Amenity::query()->findOrFail($this->editingId)->update($payload);
        session()->flash('success', 'Amenity updated successfully.');
    } else {
        Amenity::query()->create($payload);
        session()->flash('success', 'Amenity created successfully.');
    }

    $this->resetForm();
};

$getSortIcon = function (string $field): string {
    if ($this->sortField !== $field) {
        return '↕';
    }

    return $this->sortDirection === 'asc' ? '↑' : '↓';
};

$getTypeBadgeClass = function (string $type): string {
    return match ($type) {
        'Rentable' => 'bg-blue-50 text-blue-700 ring-1 ring-blue-600/20 dark:bg-blue-950/40 dark:text-blue-300',
        'Inclusive' => 'bg-green-50 text-green-700 ring-1 ring-green-600/20 dark:bg-green-950/40 dark:text-green-300',
        default => 'bg-zinc-100 text-zinc-700 ring-1 ring-zinc-600/10 dark:bg-zinc-800 dark:text-zinc-300',
    };
};

$getUsageSummary = function (Amenity $amenity): string {
    $parts = [];

    if ($amenity->facility_amenities_count > 0) {
        $parts[] = $amenity->facility_amenities_count . ' facility link' . ($amenity->facility_amenities_count === 1 ? '' : 's');
    }

    if ($amenity->amenity_request_details_count > 0) {
        $parts[] = $amenity->amenity_request_details_count . ' request detail' . ($amenity->amenity_request_details_count === 1 ? '' : 's');
    }

    if ($amenity->fines_count > 0) {
        $parts[] = $amenity->fines_count . ' fine link' . ($amenity->fines_count === 1 ? '' : 's');
    }

    return $parts === [] ? 'Not linked yet' : implode(', ', $parts);
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Amenity Management</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Manage rentable amenities for billing and inclusive amenities for facility checklists.
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

    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-100">
        Inclusive amenities are not charged to guests, so their price is saved as ₱0.00. Rentable amenities must have a price because they are used later in amenity request billing.
    </div>

    <div class="grid gap-6 2xl:grid-cols-3">
        <section class="2xl:col-span-2">
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <h2 class="font-semibold">Amenity list</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Search, filter, sort, then click Edit to modify amenity master data.
                            </p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 lg:w-[34rem]">
                            <flux:input
                                wire:model.live.debounce.300ms="search"
                                label="Search"
                                placeholder="Name, type, description, price"
                                clearable
                            />

                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Type</label>
                                <select
                                    wire:model.live="typeFilter"
                                    class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                                >
                                    <option value="">All types</option>
                                    <option value="Rentable">Rentable</option>
                                    <option value="Inclusive">Inclusive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-800">
                        <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-950/60 dark:text-zinc-400">
                            <tr>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('amenity_name')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Amenity <span>{{ $this->getSortIcon('amenity_name') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('amenity_description')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Description <span>{{ $this->getSortIcon('amenity_description') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('amenity_type')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Type <span>{{ $this->getSortIcon('amenity_type') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('amenity_price')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Price <span>{{ $this->getSortIcon('amenity_price') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('usage')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Usage <span>{{ $this->getSortIcon('usage') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 text-right font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->amenities as $amenity)
                                <tr wire:key="amenity-{{ $amenity->amenity_id }}">
                                    <td class="px-5 py-4 font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $amenity->amenityName?->amenity_name ?? 'Unnamed amenity' }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $amenity->amenity_description }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $this->getTypeBadgeClass($amenity->amenity_type) }}">
                                            {{ $amenity->amenity_type }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        ₱{{ number_format((float) $amenity->amenity_price, 2) }}
                                    </td>
                                    <td class="max-w-xs px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $this->getUsageSummary($amenity) }}
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <flux:button
                                            type="button"
                                            size="sm"
                                            variant="subtle"
                                            wire:click="startEditing({{ $amenity->amenity_id }})"
                                        >
                                            Edit
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                        No amenities found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <flux:pagination :paginator="$this->amenities" />
                </div>
            </div>
        </section>

        <section>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-semibold">{{ $editingId ? 'Edit amenity' : 'Create amenity' }}</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Use exact labels because these appear in facility setup, requests, fines, and reports.
                        </p>
                    </div>

                    @if ($editingId)
                        <flux:button type="button" size="sm" variant="subtle" wire:click="createNew">
                            New
                        </flux:button>
                    @endif
                </div>

                <form wire:submit="save" class="mt-5 space-y-4">
                    <flux:input
                        wire:model="amenityName"
                        label="Amenity name"
                        placeholder="Example: Chair, Pillow, Table Set"
                    />

                    <flux:input
                        wire:model="amenityDescription"
                        label="Description"
                        placeholder="Example: Monoblock, Room inclusive item"
                    />

                    <div>
                        <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Amenity type</label>
                        <select
                            wire:model.live="amenityType"
                            class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                        >
                            <option value="Rentable">Rentable</option>
                            <option value="Inclusive">Inclusive</option>
                        </select>
                        @error('amenityType')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($amenityType === 'Inclusive')
                        <flux:input
                            wire:model="amenityPrice"
                            type="number"
                            step="0.01"
                            min="0"
                            label="Price"
                            placeholder="0.00"
                            readonly
                        />
                        <p class="-mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                            Inclusive amenities are included in facilities and will be saved as ₱0.00.
                        </p>
                    @else
                        <flux:input
                            wire:model="amenityPrice"
                            type="number"
                            step="0.01"
                            min="0"
                            label="Price"
                            placeholder="Example: 300.00"
                        />
                    @endif

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <flux:button type="button" variant="subtle" wire:click="resetForm">
                            Clear
                        </flux:button>

                        <flux:button type="submit" variant="primary">
                            {{ $editingId ? 'Save changes' : 'Create amenity' }}
                        </flux:button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>

<?php

use App\Models\Amenity;
use App\Models\Facility;
use App\Models\FacilityAmenity;
use App\Models\FacilityPrice;
use App\Models\FacilityType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, state, title, updated, usesPagination};

layout('layouts.app');
title('Facility Management - Olaer Spring Resort');
usesPagination();

state([
    'search' => '',
    'typeFilter' => '',
    'statusFilter' => '',
    'sortField' => 'facility_name',
    'sortDirection' => 'asc',

    'editingId' => null,
    'facilityName' => '',
    'facilityTypeId' => '',
    'facilitySize' => '',
    'capacity' => '',
    'facilityStatus' => 'Available',
    'statusLocked' => false,

    'priceRows' => [
        ['rate_type' => 'Day Rate', 'facility_price' => ''],
    ],
    'amenityRows' => [
        ['amenity_id' => '', 'amenity_quantity' => ''],
    ],
]);

$facilityTypes = computed(function () {
    return FacilityType::query()
        ->orderBy('facility_type')
        ->get();
});

$amenities = computed(function () {
    return Amenity::query()
        ->with('amenityName')
        ->get()
        ->sortBy(fn (Amenity $amenity) => $amenity->amenityName?->amenity_name ?? '')
        ->values();
});

$facilities = computed(function () {
    $allowedSorts = [
        'facility_name',
        'facility_size',
        'facility_status',
        'capacity',
    ];

    $sortField = in_array($this->sortField, $allowedSorts, true)
        ? $this->sortField
        : 'facility_name';

    $sortDirection = $this->sortDirection === 'desc' ? 'desc' : 'asc';

    return Facility::query()
        ->with([
            'facilityType',
            'prices' => fn ($query) => $query->orderBy('rate_type'),
            'facilityAmenities.amenity.amenityName',
        ])
        ->when(trim($this->search) !== '', function ($query) {
            $search = '%' . trim($this->search) . '%';

            $query->where(function ($query) use ($search) {
                $query->where('facility_name', 'like', $search)
                    ->orWhere('facility_size', 'like', $search)
                    ->orWhere('capacity', 'like', $search)
                    ->orWhere('facility_status', 'like', $search)
                    ->orWhereHas('facilityType', function ($query) use ($search) {
                        $query->where('facility_type', 'like', $search);
                    });
            });
        })
        ->when($this->typeFilter !== '', function ($query) {
            $query->where('facility_type_id', $this->typeFilter);
        })
        ->when($this->statusFilter !== '', function ($query) {
            $query->where('facility_status', $this->statusFilter);
        })
        ->orderBy($sortField, $sortDirection)
        ->paginate(10);
});

$sortBy = function (string $field): void {
    $allowedSorts = [
        'facility_name',
        'facility_size',
        'facility_status',
        'capacity',
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
    'statusFilter' => function (): void {
        $this->resetPage();
    },
]);

$createNew = function (): void {
    $this->resetForm();
};

$startEditing = function (int $facilityId): void {
    $facility = Facility::query()
        ->with(['prices', 'facilityAmenities'])
        ->findOrFail($facilityId);

    $this->editingId = $facility->facility_id;
    $this->facilityName = $facility->facility_name;
    $this->facilityTypeId = (string) $facility->facility_type_id;
    $this->facilitySize = $facility->facility_size;
    $this->capacity = $facility->capacity;
    $this->facilityStatus = $facility->facility_status;
    $this->statusLocked = in_array($facility->facility_status, ['Booked', 'Occupied'], true);

    $this->priceRows = $facility->prices
        ->map(fn (FacilityPrice $price) => [
            'rate_type' => $price->rate_type,
            'facility_price' => number_format((float) $price->facility_price, 2, '.', ''),
        ])
        ->values()
        ->all();

    if ($this->priceRows === []) {
        $this->priceRows = [['rate_type' => 'Day Rate', 'facility_price' => '']];
    }

    $this->amenityRows = $facility->facilityAmenities
        ->map(fn (FacilityAmenity $facilityAmenity) => [
            'amenity_id' => (string) $facilityAmenity->amenity_id,
            'amenity_quantity' => (string) $facilityAmenity->amenity_quantity,
        ])
        ->values()
        ->all();

    if ($this->amenityRows === []) {
        $this->amenityRows = [['amenity_id' => '', 'amenity_quantity' => '']];
    }

    $this->resetValidation();
};

$resetForm = function (): void {
    $this->editingId = null;
    $this->facilityName = '';
    $this->facilityTypeId = '';
    $this->facilitySize = '';
    $this->capacity = '';
    $this->facilityStatus = 'Available';
    $this->statusLocked = false;

    $this->priceRows = [
        ['rate_type' => 'Day Rate', 'facility_price' => ''],
    ];

    $this->amenityRows = [
        ['amenity_id' => '', 'amenity_quantity' => ''],
    ];

    $this->resetValidation();
};

$addPriceRow = function (): void {
    $this->priceRows[] = ['rate_type' => '', 'facility_price' => ''];
};

$removePriceRow = function (int $index): void {
    unset($this->priceRows[$index]);
    $this->priceRows = array_values($this->priceRows);

    if ($this->priceRows === []) {
        $this->priceRows = [['rate_type' => 'Day Rate', 'facility_price' => '']];
    }
};

$addAmenityRow = function (): void {
    $this->amenityRows[] = ['amenity_id' => '', 'amenity_quantity' => ''];
};

$removeAmenityRow = function (int $index): void {
    unset($this->amenityRows[$index]);
    $this->amenityRows = array_values($this->amenityRows);

    if ($this->amenityRows === []) {
        $this->amenityRows = [['amenity_id' => '', 'amenity_quantity' => '']];
    }
};

$save = function (): void {
    $validated = $this->validate([
        'editingId' => ['nullable', 'integer', 'exists:tbl_facility,facility_id'],
        'facilityName' => [
            'required',
            'string',
            'max:50',
            Rule::unique('tbl_facility', 'facility_name')->ignore($this->editingId, 'facility_id'),
        ],
        'facilityTypeId' => ['required', 'integer', 'exists:tbl_facility_type,facility_type_id'],
        'facilitySize' => ['required', 'string', 'max:50'],
        'capacity' => ['required', 'string', 'max:50'],
        'facilityStatus' => ['required', Rule::in(['Available', 'Unavailable', 'Booked', 'Occupied'])],

        'priceRows' => ['required', 'array', 'min:1'],
        'priceRows.*.rate_type' => ['nullable', 'string', 'max:20'],
        'priceRows.*.facility_price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

        'amenityRows' => ['nullable', 'array'],
        'amenityRows.*.amenity_id' => ['nullable', 'integer', 'exists:tbl_amenity,amenity_id'],
        'amenityRows.*.amenity_quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
    ], [
        'facilityName.required' => 'Facility name is required.',
        'facilityName.unique' => 'This facility name is already used.',
        'facilityTypeId.required' => 'Facility type is required.',
        'facilityTypeId.exists' => 'Selected facility type does not exist.',
        'facilitySize.required' => 'Facility size is required.',
        'capacity.required' => 'Capacity is required.',
        'facilityStatus.in' => 'Status must be Available, Unavailable, Booked, or Occupied.',
        'priceRows.required' => 'Add at least one price row.',
        'priceRows.*.facility_price.numeric' => 'Facility price must be a valid number.',
        'amenityRows.*.amenity_quantity.integer' => 'Amenity quantity must be a whole number.',
    ]);

    $cleanPrices = collect($validated['priceRows'])
        ->map(fn (array $row) => [
            'rate_type' => trim((string) ($row['rate_type'] ?? '')),
            'facility_price' => $row['facility_price'] ?? null,
        ])
        ->filter(fn (array $row) => $row['rate_type'] !== '' || filled($row['facility_price']))
        ->values();

    if ($cleanPrices->isEmpty()) {
        $this->addError('priceRows', 'Add at least one valid price for this facility.');
        return;
    }

    foreach ($cleanPrices as $index => $row) {
        if ($row['rate_type'] === '' || ! filled($row['facility_price'])) {
            $this->addError('priceRows.' . $index . '.rate_type', 'Each price row needs both a rate type and a price.');
            return;
        }
    }

    if ($cleanPrices->pluck('rate_type')->count() !== $cleanPrices->pluck('rate_type')->unique()->count()) {
        $this->addError('priceRows', 'Rate types must not be duplicated for the same facility.');
        return;
    }

    $cleanAmenities = collect($validated['amenityRows'] ?? [])
        ->map(fn (array $row) => [
            'amenity_id' => $row['amenity_id'] ?? null,
            'amenity_quantity' => $row['amenity_quantity'] ?? null,
        ])
        ->filter(fn (array $row) => filled($row['amenity_id']) || filled($row['amenity_quantity']))
        ->values();

    foreach ($cleanAmenities as $index => $row) {
        if (! filled($row['amenity_id']) || ! filled($row['amenity_quantity'])) {
            $this->addError('amenityRows.' . $index . '.amenity_id', 'Each amenity row needs both an amenity and a quantity.');
            return;
        }
    }

    if ($cleanAmenities->pluck('amenity_id')->count() !== $cleanAmenities->pluck('amenity_id')->unique()->count()) {
        $this->addError('amenityRows', 'Do not select the same amenity more than once for a facility.');
        return;
    }

    $currentFacility = $this->editingId
        ? Facility::query()->findOrFail($this->editingId)
        : null;

    $statusIsLocked = $currentFacility && in_array($currentFacility->facility_status, ['Booked', 'Occupied'], true);

    if (! $statusIsLocked && ! in_array($validated['facilityStatus'], ['Available', 'Unavailable'], true)) {
        $this->addError('facilityStatus', 'Only booking/check-in workflows should set a facility to Booked or Occupied.');
        return;
    }

    DB::transaction(function () use ($validated, $cleanPrices, $cleanAmenities, $currentFacility, $statusIsLocked): void {
        $payload = [
            'facility_name' => trim($validated['facilityName']),
            'facility_type_id' => (int) $validated['facilityTypeId'],
            'facility_size' => trim($validated['facilitySize']),
            'capacity' => trim($validated['capacity']),
            'facility_status' => $statusIsLocked
                ? $currentFacility->facility_status
                : $validated['facilityStatus'],
        ];

        if ($currentFacility) {
            $currentFacility->update($payload);
            $facility = $currentFacility->refresh();
        } else {
            $facility = Facility::query()->create($payload);
        }

        FacilityPrice::query()
            ->where('facility_id', $facility->facility_id)
            ->delete();

        foreach ($cleanPrices as $row) {
            FacilityPrice::query()->create([
                'facility_id' => $facility->facility_id,
                'rate_type' => $row['rate_type'],
                'facility_price' => round((float) $row['facility_price'], 2),
            ]);
        }

        FacilityAmenity::query()
            ->where('facility_id', $facility->facility_id)
            ->delete();

        foreach ($cleanAmenities as $row) {
            FacilityAmenity::query()->create([
                'facility_id' => $facility->facility_id,
                'amenity_id' => (int) $row['amenity_id'],
                'amenity_quantity' => (int) $row['amenity_quantity'],
            ]);
        }
    });

    session()->flash('success', $this->editingId ? 'Facility updated successfully.' : 'Facility created successfully.');
    $this->resetForm();
};

$getSortIcon = function (string $field): string {
    if ($this->sortField !== $field) {
        return '↕';
    }

    return $this->sortDirection === 'asc' ? '↑' : '↓';
};

$getPriceSummary = function (Facility $facility): string {
    $summary = $facility->prices
        ->map(fn (FacilityPrice $price) => $price->rate_type . ': ₱' . number_format((float) $price->facility_price, 2))
        ->implode(', ');

    return $summary !== '' ? $summary : 'No price set';
};

$getAmenitySummary = function (Facility $facility): string {
    $summary = $facility->facilityAmenities
        ->map(function (FacilityAmenity $facilityAmenity) {
            $name = $facilityAmenity->amenity?->amenityName?->amenity_name ?? 'Unknown amenity';
            return $name . ' x' . $facilityAmenity->amenity_quantity;
        })
        ->implode(', ');

    return $summary !== '' ? $summary : 'No inclusive amenities';
};

$getStatusBadgeClass = function (string $status): string {
    return match ($status) {
        'Available' => 'bg-green-50 text-green-700 ring-1 ring-green-600/20 dark:bg-green-950/40 dark:text-green-300',
        'Unavailable' => 'bg-red-50 text-red-700 ring-1 ring-red-600/20 dark:bg-red-950/40 dark:text-red-300',
        'Booked' => 'bg-blue-50 text-blue-700 ring-1 ring-blue-600/20 dark:bg-blue-950/40 dark:text-blue-300',
        'Occupied' => 'bg-amber-50 text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-300',
        default => 'bg-zinc-100 text-zinc-700 ring-1 ring-zinc-600/10 dark:bg-zinc-800 dark:text-zinc-300',
    };
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Facility Management</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Create and update cottages, rooms, function halls, prices, capacity, and inclusive amenities.
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

    <div class="grid gap-6 2xl:grid-cols-3">
        <section class="2xl:col-span-2">
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <h2 class="font-semibold">Facility list</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Search, filter, sort, then click Edit to modify facility master data.
                            </p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-3 lg:w-[42rem]">
                            <flux:input
                                wire:model.live.debounce.300ms="search"
                                label="Search"
                                placeholder="Name, size, capacity, type"
                                clearable
                            />

                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Type</label>
                                <select
                                    wire:model.live="typeFilter"
                                    class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                                >
                                    <option value="">All types</option>
                                    @foreach ($this->facilityTypes as $type)
                                        <option value="{{ $type->facility_type_id }}">{{ $type->facility_type }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Status</label>
                                <select
                                    wire:model.live="statusFilter"
                                    class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                                >
                                    <option value="">All statuses</option>
                                    <option value="Available">Available</option>
                                    <option value="Unavailable">Unavailable</option>
                                    <option value="Booked">Booked</option>
                                    <option value="Occupied">Occupied</option>
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
                                    <button type="button" wire:click="sortBy('facility_name')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Facility <span>{{ $this->getSortIcon('facility_name') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">Type</th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('facility_size')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Size <span>{{ $this->getSortIcon('facility_size') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('capacity')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Capacity <span>{{ $this->getSortIcon('capacity') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">Prices</th>
                                <th class="px-5 py-3 font-semibold">Amenities</th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('facility_status')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Status <span>{{ $this->getSortIcon('facility_status') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 text-right font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->facilities as $facility)
                                <tr wire:key="facility-{{ $facility->facility_id }}">
                                    <td class="px-5 py-4 font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $facility->facility_name }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $facility->facilityType?->facility_type ?? 'No type' }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $facility->facility_size }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $facility->capacity }}
                                    </td>
                                    <td class="max-w-xs px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $this->getPriceSummary($facility) }}
                                    </td>
                                    <td class="max-w-xs px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $this->getAmenitySummary($facility) }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $this->getStatusBadgeClass($facility->facility_status) }}">
                                            {{ $facility->facility_status }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <flux:button
                                            type="button"
                                            size="sm"
                                            variant="subtle"
                                            wire:click="startEditing({{ $facility->facility_id }})"
                                        >
                                            Edit
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-5 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                        No facilities found. Create your first facility using the form.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <flux:pagination :paginator="$this->facilities" />
                </div>
            </div>
        </section>

        <section>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">{{ $editingId ? 'Edit facility' : 'Create facility' }}</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Master data only. Booked/occupied statuses are handled later by booking and check-in workflows.
                        </p>
                    </div>

                    <flux:button type="button" size="sm" variant="subtle" wire:click="createNew">
                        New
                    </flux:button>
                </div>

                <form wire:submit="save" class="mt-5 space-y-5">
                    <div>
                        <flux:input
                            wire:model="facilityName"
                            label="Facility name"
                            placeholder="C300-001, R-001, FH-1"
                        />
                        @error('facilityName')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Facility type</label>
                        <select
                            wire:model="facilityTypeId"
                            class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                        >
                            <option value="">Choose type</option>
                            @foreach ($this->facilityTypes as $type)
                                <option value="{{ $type->facility_type_id }}">{{ $type->facility_type }}</option>
                            @endforeach
                        </select>
                        @error('facilityTypeId')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <flux:input
                                wire:model="facilitySize"
                                label="Facility size"
                                placeholder="Small Cottage"
                            />
                            @error('facilitySize')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <flux:input
                                wire:model="capacity"
                                label="Capacity"
                                placeholder="4-6"
                            />
                            @error('capacity')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Status</label>

                        @if ($statusLocked)
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200">
                                This facility is currently <strong>{{ $facilityStatus }}</strong>. The master data page will not override active booking/check-in status.
                            </div>
                        @else
                            <select
                                wire:model="facilityStatus"
                                class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                            >
                                <option value="Available">Available</option>
                                <option value="Unavailable">Unavailable</option>
                            </select>
                        @endif

                        @error('facilityStatus')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="font-medium">Facility prices</h3>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Examples: Day Rate, Night Rate, Overnight, Extension.</p>
                            </div>

                            <flux:button type="button" size="sm" variant="subtle" wire:click="addPriceRow">
                                Add price
                            </flux:button>
                        </div>

                        <div class="mt-4 space-y-3">
                            @foreach ($priceRows as $index => $row)
                                <div class="grid gap-2 sm:grid-cols-[1fr_8rem_auto]" wire:key="price-row-{{ $index }}">
                                    <input
                                        wire:model="priceRows.{{ $index }}.rate_type"
                                        type="text"
                                        placeholder="Rate type"
                                        class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                                    />

                                    <input
                                        wire:model="priceRows.{{ $index }}.facility_price"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        placeholder="Price"
                                        class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                                    />

                                    <flux:button type="button" size="sm" variant="danger" wire:click="removePriceRow({{ $index }})">
                                        Remove
                                    </flux:button>
                                </div>
                            @endforeach

                            @error('priceRows')
                                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            @error('priceRows.*.rate_type')
                                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            @error('priceRows.*.facility_price')
                                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="font-medium">Inclusive amenities</h3>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Optional. Use this for room/function-hall included items.</p>
                            </div>

                            <flux:button type="button" size="sm" variant="subtle" wire:click="addAmenityRow">
                                Add amenity
                            </flux:button>
                        </div>

                        <div class="mt-4 space-y-3">
                            @foreach ($amenityRows as $index => $row)
                                <div class="grid gap-2 sm:grid-cols-[1fr_6rem_auto]" wire:key="amenity-row-{{ $index }}">
                                    <select
                                        wire:model="amenityRows.{{ $index }}.amenity_id"
                                        class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                                    >
                                        <option value="">Choose amenity</option>
                                        @foreach ($this->amenities as $amenity)
                                            <option value="{{ $amenity->amenity_id }}">
                                                {{ $amenity->amenityName?->amenity_name ?? 'Unnamed' }} — {{ $amenity->amenity_type }}
                                            </option>
                                        @endforeach
                                    </select>

                                    <input
                                        wire:model="amenityRows.{{ $index }}.amenity_quantity"
                                        type="number"
                                        min="1"
                                        step="1"
                                        placeholder="Qty"
                                        class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                                    />

                                    <flux:button type="button" size="sm" variant="danger" wire:click="removeAmenityRow({{ $index }})">
                                        Remove
                                    </flux:button>
                                </div>
                            @endforeach

                            @error('amenityRows')
                                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            @error('amenityRows.*.amenity_id')
                                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            @error('amenityRows.*.amenity_quantity')
                                <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex gap-2 pt-2">
                        <flux:button type="submit" variant="primary">
                            {{ $editingId ? 'Save changes' : 'Create facility' }}
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

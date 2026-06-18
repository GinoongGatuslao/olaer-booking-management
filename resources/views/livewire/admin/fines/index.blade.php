<?php

use App\Models\Amenity;
use App\Models\DamageType;
use App\Models\Fine;
use Illuminate\Validation\Rule;
use function Livewire\Volt\{computed, layout, state, title};

layout('layouts.app');
title('Fines Management - Olaer Spring Resort');

state([
    'search' => '',
    'typeFilter' => '',
    'sortField' => 'fine_name',
    'sortDirection' => 'asc',

    'editingFineId' => null,
    'fineType' => 'Situational',
    'amenityId' => '',
    'damageTypeId' => '',
    'situationalFine' => '',
    'situationalFineDescription' => '',
    'fineCharge' => '',

    'editingDamageTypeId' => null,
    'damageType' => '',
]);

$amenities = computed(function () {
    return Amenity::query()
        ->with('amenityName')
        ->get()
        ->sortBy(fn (Amenity $amenity) => ($amenity->amenityName?->amenity_name ?? '') . ' ' . $amenity->amenity_description, SORT_NATURAL | SORT_FLAG_CASE)
        ->values();
});

$damageTypes = computed(function () {
    return DamageType::query()
        ->withCount('fines')
        ->get()
        ->sortBy('damage_type', SORT_NATURAL | SORT_FLAG_CASE)
        ->values();
});

$fines = computed(function () {
    $search = trim($this->search);
    $sortDirection = $this->sortDirection === 'desc' ? 'desc' : 'asc';

    $fines = Fine::query()
        ->with(['amenity.amenityName', 'damageType'])
        ->withCount('guestFines')
        ->when($this->typeFilter !== '', function ($query) {
            $query->where('fine_type', $this->typeFilter);
        })
        ->when($search !== '', function ($query) use ($search) {
            $like = '%' . $search . '%';

            $query->where(function ($query) use ($like) {
                $query->where('fine_type', 'like', $like)
                    ->orWhere('situational_fine', 'like', $like)
                    ->orWhere('situational_fine_description', 'like', $like)
                    ->orWhere('fine_charge', 'like', $like)
                    ->orWhereHas('amenity.amenityName', function ($query) use ($like) {
                        $query->where('amenity_name', 'like', $like);
                    })
                    ->orWhereHas('amenity', function ($query) use ($like) {
                        $query->where('amenity_description', 'like', $like);
                    })
                    ->orWhereHas('damageType', function ($query) use ($like) {
                        $query->where('damage_type', 'like', $like);
                    });
            });
        })
        ->get();

    return match ($this->sortField) {
        'fine_type' => $sortDirection === 'asc'
            ? $fines->sortBy('fine_type', SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $fines->sortByDesc('fine_type', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        'damage_type' => $sortDirection === 'asc'
            ? $fines->sortBy(fn (Fine $fine) => $fine->damageType?->damage_type ?? '', SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $fines->sortByDesc(fn (Fine $fine) => $fine->damageType?->damage_type ?? '', SORT_NATURAL | SORT_FLAG_CASE)->values(),
        'fine_charge' => $sortDirection === 'asc'
            ? $fines->sortBy(fn (Fine $fine) => (float) $fine->fine_charge)->values()
            : $fines->sortByDesc(fn (Fine $fine) => (float) $fine->fine_charge)->values(),
        'usage' => $sortDirection === 'asc'
            ? $fines->sortBy(fn (Fine $fine) => $fine->guest_fines_count)->values()
            : $fines->sortByDesc(fn (Fine $fine) => $fine->guest_fines_count)->values(),
        default => $sortDirection === 'asc'
            ? $fines->sortBy(fn (Fine $fine) => $this->getFineName($fine), SORT_NATURAL | SORT_FLAG_CASE)->values()
            : $fines->sortByDesc(fn (Fine $fine) => $this->getFineName($fine), SORT_NATURAL | SORT_FLAG_CASE)->values(),
    };
});

$createNewFine = function (): void {
    $this->resetFineForm();
};

$startEditingFine = function (int $fineId): void {
    $fine = Fine::query()
        ->with(['amenity', 'damageType'])
        ->findOrFail($fineId);

    $this->editingFineId = $fine->fine_id;
    $this->fineType = $fine->fine_type;
    $this->amenityId = $fine->amenity_id ? (string) $fine->amenity_id : '';
    $this->damageTypeId = $fine->damage_type_id ? (string) $fine->damage_type_id : '';
    $this->situationalFine = $fine->situational_fine ?? '';
    $this->situationalFineDescription = $fine->situational_fine_description ?? '';
    $this->fineCharge = number_format((float) $fine->fine_charge, 2, '.', '');

    $this->resetValidation();
};

$resetFineForm = function (): void {
    $this->editingFineId = null;
    $this->fineType = 'Situational';
    $this->amenityId = '';
    $this->damageTypeId = '';
    $this->situationalFine = '';
    $this->situationalFineDescription = '';
    $this->fineCharge = '';

    $this->resetValidation();
};

$updatedFineType = function (): void {
    if ($this->fineType === 'Amenity') {
        $this->situationalFine = '';
        $this->situationalFineDescription = '';
    }

    if ($this->fineType === 'Situational') {
        $this->amenityId = '';
        $this->damageTypeId = '';
    }
};

$sortBy = function (string $field): void {
    $allowedSorts = [
        'fine_name',
        'fine_type',
        'damage_type',
        'fine_charge',
        'usage',
    ];

    if (! in_array($field, $allowedSorts, true)) {
        return;
    }

    if ($this->sortField === $field) {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        return;
    }

    $this->sortField = $field;
    $this->sortDirection = 'asc';
};

$saveFine = function (): void {
    $validated = $this->validate([
        'editingFineId' => ['nullable', 'integer', 'exists:tbl_fine,fine_id'],
        'fineType' => ['required', Rule::in(['Amenity', 'Situational'])],
        'amenityId' => ['nullable', 'integer', 'exists:tbl_amenity,amenity_id'],
        'damageTypeId' => ['nullable', 'integer', 'exists:tbl_damage_type,damage_type_id'],
        'situationalFine' => ['nullable', 'string', 'max:50'],
        'situationalFineDescription' => ['nullable', 'string', 'max:100'],
        'fineCharge' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
    ], [
        'fineType.in' => 'Fine type must be Amenity or Situational.',
        'amenityId.exists' => 'Selected amenity does not exist.',
        'damageTypeId.exists' => 'Selected damage type does not exist.',
        'situationalFine.max' => 'Situational fine name must not exceed 50 characters.',
        'situationalFineDescription.max' => 'Description must not exceed 100 characters.',
        'fineCharge.required' => 'Fine charge is required.',
        'fineCharge.numeric' => 'Fine charge must be a valid amount.',
        'fineCharge.min' => 'Fine charge must be greater than zero.',
    ]);

    $type = $validated['fineType'];
    $charge = round((float) $validated['fineCharge'], 2);

    if ($type === 'Amenity') {
        if (! $validated['amenityId']) {
            $this->addError('amenityId', 'Amenity fines must be linked to an amenity.');
            return;
        }

        if (! $validated['damageTypeId']) {
            $this->addError('damageTypeId', 'Amenity fines must be linked to a damage type.');
            return;
        }

        $duplicate = Fine::query()
            ->where('fine_type', 'Amenity')
            ->where('amenity_id', $validated['amenityId'])
            ->where('damage_type_id', $validated['damageTypeId'])
            ->when($this->editingFineId, function ($query) {
                $query->where('fine_id', '!=', $this->editingFineId);
            })
            ->exists();

        if ($duplicate) {
            $this->addError('amenityId', 'This amenity already has a fine for the selected damage type.');
            return;
        }

        $payload = [
            'fine_type' => 'Amenity',
            'amenity_id' => (int) $validated['amenityId'],
            'damage_type_id' => (int) $validated['damageTypeId'],
            'situational_fine' => null,
            'situational_fine_description' => null,
            'fine_charge' => $charge,
        ];
    } else {
        $name = trim(preg_replace('/\s+/', ' ', (string) $validated['situationalFine']));
        $description = trim(preg_replace('/\s+/', ' ', (string) ($validated['situationalFineDescription'] ?? '')));

        if ($name === '') {
            $this->addError('situationalFine', 'Situational fine name is required.');
            return;
        }

        $duplicate = Fine::query()
            ->where('fine_type', 'Situational')
            ->whereRaw('LOWER(situational_fine) = ?', [mb_strtolower($name)])
            ->when($this->editingFineId, function ($query) {
                $query->where('fine_id', '!=', $this->editingFineId);
            })
            ->exists();

        if ($duplicate) {
            $this->addError('situationalFine', 'This situational fine already exists.');
            return;
        }

        $payload = [
            'fine_type' => 'Situational',
            'amenity_id' => null,
            'damage_type_id' => null,
            'situational_fine' => $name,
            'situational_fine_description' => $description !== '' ? $description : null,
            'fine_charge' => $charge,
        ];
    }

    if ($this->editingFineId) {
        Fine::query()->findOrFail($this->editingFineId)->update($payload);
        session()->flash('success', 'Fine updated successfully.');
    } else {
        Fine::query()->create($payload);
        session()->flash('success', 'Fine created successfully.');
    }

    $this->resetFineForm();
};

$startEditingDamageType = function (int $damageTypeId): void {
    $damageType = DamageType::query()->findOrFail($damageTypeId);

    $this->editingDamageTypeId = $damageType->damage_type_id;
    $this->damageType = $damageType->damage_type;

    $this->resetValidation();
};

$resetDamageTypeForm = function (): void {
    $this->editingDamageTypeId = null;
    $this->damageType = '';

    $this->resetValidation();
};

$saveDamageType = function (): void {
    $validated = $this->validate([
        'editingDamageTypeId' => ['nullable', 'integer', 'exists:tbl_damage_type,damage_type_id'],
        'damageType' => ['required', 'string', 'max:50'],
    ], [
        'damageType.required' => 'Damage type is required.',
        'damageType.max' => 'Damage type must not exceed 50 characters.',
    ]);

    $name = trim(preg_replace('/\s+/', ' ', $validated['damageType']));

    $duplicate = DamageType::query()
        ->whereRaw('LOWER(damage_type) = ?', [mb_strtolower($name)])
        ->when($this->editingDamageTypeId, function ($query) {
            $query->where('damage_type_id', '!=', $this->editingDamageTypeId);
        })
        ->exists();

    if ($duplicate) {
        $this->addError('damageType', 'This damage type already exists.');
        return;
    }

    if ($this->editingDamageTypeId) {
        DamageType::query()->findOrFail($this->editingDamageTypeId)->update([
            'damage_type' => $name,
        ]);
        session()->flash('success', 'Damage type updated successfully.');
    } else {
        DamageType::query()->create([
            'damage_type' => $name,
        ]);
        session()->flash('success', 'Damage type created successfully.');
    }

    $this->resetDamageTypeForm();
};

$getSortIcon = function (string $field): string {
    if ($this->sortField !== $field) {
        return '↕';
    }

    return $this->sortDirection === 'asc' ? '↑' : '↓';
};

$getFineName = function (Fine $fine): string {
    if ($fine->fine_type === 'Amenity') {
        $amenityName = $fine->amenity?->amenityName?->amenity_name ?? 'Unknown amenity';
        $description = $fine->amenity?->amenity_description;

        return $description ? $amenityName . ' - ' . $description : $amenityName;
    }

    return $fine->situational_fine ?? 'Unnamed situational fine';
};

$getFineDescription = function (Fine $fine): string {
    if ($fine->fine_type === 'Amenity') {
        return 'Amenity-related charge applied during facility inspection/check-out.';
    }

    return $fine->situational_fine_description ?: 'No description provided.';
};

$getTypeBadgeClass = function (string $type): string {
    return match ($type) {
        'Amenity' => 'bg-blue-50 text-blue-700 ring-1 ring-blue-600/20 dark:bg-blue-950/40 dark:text-blue-300',
        'Situational' => 'bg-purple-50 text-purple-700 ring-1 ring-purple-600/20 dark:bg-purple-950/40 dark:text-purple-300',
        default => 'bg-zinc-100 text-zinc-700 ring-1 ring-zinc-600/10 dark:bg-zinc-800 dark:text-zinc-300',
    };
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Fines Management</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Manage amenity damage fines and situational fines used during facility inspection and check-out.
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
        This is not amenity inventory. This module only defines charges. Inventory-style stock tracking is outside the capstone scope; damaged/missing amenity records will be handled later in the Facility Checklist and report flow.
    </div>

    <div class="grid gap-6 2xl:grid-cols-3">
        <section class="2xl:col-span-2">
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <h2 class="font-semibold">Fine list</h2>
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                Search, filter, sort, then click Edit to modify fine master data.
                            </p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 lg:w-[34rem]">
                            <flux:input
                                wire:model.live.debounce.300ms="search"
                                label="Search"
                                placeholder="Fine, amenity, damage type, charge"
                                clearable
                            />

                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Type</label>
                                <select
                                    wire:model.live="typeFilter"
                                    class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                                >
                                    <option value="">All types</option>
                                    <option value="Amenity">Amenity</option>
                                    <option value="Situational">Situational</option>
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
                                    <button type="button" wire:click="sortBy('fine_name')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Fine <span>{{ $this->getSortIcon('fine_name') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('fine_type')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Type <span>{{ $this->getSortIcon('fine_type') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('damage_type')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Damage Type <span>{{ $this->getSortIcon('damage_type') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('fine_charge')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Charge <span>{{ $this->getSortIcon('fine_charge') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 font-semibold">
                                    <button type="button" wire:click="sortBy('usage')" class="inline-flex items-center gap-1 hover:text-zinc-900 dark:hover:text-white">
                                        Used <span>{{ $this->getSortIcon('usage') }}</span>
                                    </button>
                                </th>
                                <th class="px-5 py-3 text-right font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->fines as $fine)
                                <tr wire:key="fine-{{ $fine->fine_id }}">
                                    <td class="max-w-sm px-5 py-4">
                                        <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $this->getFineName($fine) }}
                                        </div>
                                        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $this->getFineDescription($fine) }}
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $this->getTypeBadgeClass($fine->fine_type) }}">
                                            {{ $fine->fine_type }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $fine->damageType?->damage_type ?? 'N/A' }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        ₱{{ number_format((float) $fine->fine_charge, 2) }}
                                    </td>
                                    <td class="px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {{ $fine->guest_fines_count }} guest fine{{ $fine->guest_fines_count === 1 ? '' : 's' }}
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <flux:button
                                            type="button"
                                            size="sm"
                                            variant="subtle"
                                            wire:click="startEditingFine({{ $fine->fine_id }})"
                                        >
                                            Edit
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                        No fines found.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="space-y-6">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-semibold">{{ $editingFineId ? 'Edit fine' : 'Create fine' }}</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Amenity fines are item-based. Situational fines are event-based.
                        </p>
                    </div>

                    @if ($editingFineId)
                        <flux:button type="button" size="sm" variant="subtle" wire:click="createNewFine">
                            New
                        </flux:button>
                    @endif
                </div>

                <form wire:submit="saveFine" class="mt-5 space-y-4">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Fine type</label>
                        <select
                            wire:model.live="fineType"
                            class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                        >
                            <option value="Situational">Situational</option>
                            <option value="Amenity">Amenity</option>
                        </select>
                        @error('fineType')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    @if ($fineType === 'Amenity')
                        <div>
                            <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Amenity</label>
                            <select
                                wire:model="amenityId"
                                class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                            >
                                <option value="">Select amenity</option>
                                @foreach ($this->amenities as $amenity)
                                    <option value="{{ $amenity->amenity_id }}">
                                        {{ $amenity->amenityName?->amenity_name ?? 'Unnamed amenity' }} - {{ $amenity->amenity_description }}
                                    </option>
                                @endforeach
                            </select>
                            @error('amenityId')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Damage type</label>
                            <select
                                wire:model="damageTypeId"
                                class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-900 focus:outline-none focus:ring-1 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-950 dark:text-zinc-100 dark:focus:border-white dark:focus:ring-white"
                            >
                                <option value="">Select damage type</option>
                                @foreach ($this->damageTypes as $damageTypeOption)
                                    <option value="{{ $damageTypeOption->damage_type_id }}">
                                        {{ $damageTypeOption->damage_type }}
                                    </option>
                                @endforeach
                            </select>
                            @error('damageTypeId')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @else
                        <flux:input
                            wire:model="situationalFine"
                            label="Situational fine"
                            placeholder="Example: Stained Fabric"
                        />

                        <flux:input
                            wire:model="situationalFineDescription"
                            label="Description"
                            placeholder="Example: Any stained fabric assessed by maintenance staff"
                        />
                    @endif

                    <flux:input
                        wire:model="fineCharge"
                        type="number"
                        step="0.01"
                        min="0"
                        label="Fine charge"
                        placeholder="Example: 200.00"
                    />

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <flux:button type="button" variant="subtle" wire:click="resetFineForm">
                            Clear
                        </flux:button>

                        <flux:button type="submit" variant="primary">
                            {{ $editingFineId ? 'Save changes' : 'Create fine' }}
                        </flux:button>
                    </div>
                </form>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-semibold">Damage types</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Used by amenity fines during inspections.
                        </p>
                    </div>

                    @if ($editingDamageTypeId)
                        <flux:button type="button" size="sm" variant="subtle" wire:click="resetDamageTypeForm">
                            New
                        </flux:button>
                    @endif
                </div>

                <form wire:submit="saveDamageType" class="mt-5 space-y-4">
                    <flux:input
                        wire:model="damageType"
                        label="Damage type name"
                        placeholder="Example: Damaged, Missing, Stained"
                    />

                    <div class="flex items-center justify-end gap-3">
                        <flux:button type="button" variant="subtle" wire:click="resetDamageTypeForm">
                            Clear
                        </flux:button>

                        <flux:button type="submit" variant="primary">
                            {{ $editingDamageTypeId ? 'Save damage type' : 'Create damage type' }}
                        </flux:button>
                    </div>
                </form>

                <div class="mt-5 divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                    @forelse ($this->damageTypes as $damageTypeRow)
                        <div wire:key="damage-type-{{ $damageTypeRow->damage_type_id }}" class="flex items-center justify-between gap-3 px-4 py-3">
                            <div>
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $damageTypeRow->damage_type }}
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $damageTypeRow->fines_count }} linked fine{{ $damageTypeRow->fines_count === 1 ? '' : 's' }}
                                </div>
                            </div>

                            <flux:button
                                type="button"
                                size="sm"
                                variant="subtle"
                                wire:click="startEditingDamageType({{ $damageTypeRow->damage_type_id }})"
                            >
                                Edit
                            </flux:button>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">
                            No damage types found.
                        </div>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
</div>

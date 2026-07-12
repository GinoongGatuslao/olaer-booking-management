<?php

use App\Models\Amenity;
use App\Models\DamageType;
use App\Models\Fine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Fines Management - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'damage_type', except: '')]
    public string $damageTypeFilter = '';

    #[Url(as: 'sort', except: 'fine_name')]
    public string $sortField = 'fine_name';

    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $editingFineId = null;
    public string $fineType = 'Situational';
    public string $amenityId = '';
    public string $damageTypeId = '';
    public string $situationalFine = '';
    public string $situationalFineDescription = '';
    public string $fineCharge = '';

    public ?int $editingDamageTypeId = null;
    public string $damageType = '';

    #[Computed]
    public function amenities(): Collection
    {
        return Amenity::query()
            ->with('amenityName')
            ->get()
            ->sortBy(
                fn (Amenity $amenity): string =>
                    ($amenity->amenityName?->amenity_name ?? '')
                    .' '
                    .$amenity->amenity_description,
                SORT_NATURAL | SORT_FLAG_CASE,
            )
            ->values();
    }

    #[Computed]
    public function damageTypes(): Collection
    {
        return DamageType::query()
            ->withCount('fines')
            ->orderBy('damage_type')
            ->get();
    }

    #[Computed]
    public function fines(): LengthAwarePaginator
    {
        $allowedSorts = [
            'fine_name',
            'fine_type',
            'damage_type',
            'fine_charge',
            'usage',
        ];

        $sortField = in_array($this->sortField, $allowedSorts, true)
            ? $this->sortField
            : 'fine_name';

        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        $query = Fine::query()
            ->select('tbl_fine.*')
            ->leftJoin(
                'tbl_amenity',
                'tbl_amenity.amenity_id',
                '=',
                'tbl_fine.amenity_id',
            )
            ->leftJoin(
                'tbl_amenity_name',
                'tbl_amenity_name.amenity_name_id',
                '=',
                'tbl_amenity.amenity_name_id',
            )
            ->leftJoin(
                'tbl_damage_type',
                'tbl_damage_type.damage_type_id',
                '=',
                'tbl_fine.damage_type_id',
            )
            ->with([
                'amenity.amenityName',
                'damageType',
            ])
            ->withCount('guestFines')
            ->when(
                $this->typeFilter !== '',
                fn (Builder $query) => $query->where(
                    'tbl_fine.fine_type',
                    $this->typeFilter,
                ),
            )
            ->when(
                $this->damageTypeFilter !== '',
                fn (Builder $query) => $query->where(
                    'tbl_fine.damage_type_id',
                    $this->damageTypeFilter,
                ),
            )
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $like = '%'.trim($this->search).'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query->where('tbl_fine.fine_type', 'like', $like)
                        ->orWhere('tbl_fine.situational_fine', 'like', $like)
                        ->orWhere(
                            'tbl_fine.situational_fine_description',
                            'like',
                            $like,
                        )
                        ->orWhere('tbl_fine.fine_charge', 'like', $like)
                        ->orWhere(
                            'tbl_amenity_name.amenity_name',
                            'like',
                            $like,
                        )
                        ->orWhere(
                            'tbl_amenity.amenity_description',
                            'like',
                            $like,
                        )
                        ->orWhere(
                            'tbl_damage_type.damage_type',
                            'like',
                            $like,
                        );
                });
            });

        match ($sortField) {
            'fine_type' => $query
                ->orderBy('tbl_fine.fine_type', $direction)
                ->orderBy('tbl_fine.fine_id'),
            'damage_type' => $query
                ->orderBy('tbl_damage_type.damage_type', $direction)
                ->orderBy('tbl_fine.fine_id'),
            'fine_charge' => $query
                ->orderBy('tbl_fine.fine_charge', $direction)
                ->orderBy('tbl_fine.fine_id'),
            'usage' => $query
                ->orderBy('guest_fines_count', $direction)
                ->orderBy('tbl_fine.fine_id'),
            default => $query
                ->orderByRaw(
                    "CASE
                        WHEN tbl_fine.fine_type = 'Amenity'
                            THEN CONCAT(
                                COALESCE(tbl_amenity_name.amenity_name, ''),
                                ' ',
                                COALESCE(tbl_amenity.amenity_description, '')
                            )
                        ELSE COALESCE(tbl_fine.situational_fine, '')
                    END {$direction}"
                )
                ->orderBy('tbl_fine.fine_id'),
        };

        return $query->paginate($perPage);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDamageTypeFilter(): void
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

    public function updatedFineType(string $fineType): void
    {
        if ($fineType === 'Amenity') {
            $this->situationalFine = '';
            $this->situationalFineDescription = '';
        } else {
            $this->amenityId = '';
            $this->damageTypeId = '';
        }

        $this->resetValidation();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->typeFilter = '';
        $this->damageTypeFilter = '';
        $this->sortField = 'fine_name';
        $this->sortDirection = 'asc';
        $this->perPage = 10;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
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
            $this->sortDirection = $this->sortDirection === 'asc'
                ? 'desc'
                : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function createNewFine(): void
    {
        $this->resetFineForm();
    }

    public function startEditingFine(int $fineId): void
    {
        $fine = Fine::query()
            ->with([
                'amenity',
                'damageType',
            ])
            ->findOrFail($fineId);

        $this->editingFineId = (int) $fine->fine_id;
        $this->fineType = (string) $fine->fine_type;
        $this->amenityId = $fine->amenity_id
            ? (string) $fine->amenity_id
            : '';
        $this->damageTypeId = $fine->damage_type_id
            ? (string) $fine->damage_type_id
            : '';
        $this->situationalFine = (string) (
            $fine->situational_fine ?? ''
        );
        $this->situationalFineDescription = (string) (
            $fine->situational_fine_description ?? ''
        );
        $this->fineCharge = number_format(
            (float) $fine->fine_charge,
            2,
            '.',
            '',
        );

        $this->resetValidation();
    }

    public function resetFineForm(): void
    {
        $this->editingFineId = null;
        $this->fineType = 'Situational';
        $this->amenityId = '';
        $this->damageTypeId = '';
        $this->situationalFine = '';
        $this->situationalFineDescription = '';
        $this->fineCharge = '';

        $this->resetValidation();
    }

    public function saveFine(): void
    {
        $validated = $this->validate([
            'editingFineId' => [
                'nullable',
                'integer',
                'exists:tbl_fine,fine_id',
            ],
            'fineType' => [
                'required',
                Rule::in(['Amenity', 'Situational']),
            ],
            'amenityId' => [
                'nullable',
                'integer',
                'exists:tbl_amenity,amenity_id',
            ],
            'damageTypeId' => [
                'nullable',
                'integer',
                'exists:tbl_damage_type,damage_type_id',
            ],
            'situationalFine' => ['nullable', 'string', 'max:50'],
            'situationalFineDescription' => [
                'nullable',
                'string',
                'max:100',
            ],
            'fineCharge' => [
                'required',
                'numeric',
                'min:0.01',
                'max:999999.99',
            ],
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
            if (! filled($validated['amenityId'] ?? null)) {
                $this->addError(
                    'amenityId',
                    'Amenity fines must be linked to an amenity.',
                );

                return;
            }

            if (! filled($validated['damageTypeId'] ?? null)) {
                $this->addError(
                    'damageTypeId',
                    'Amenity fines must be linked to a damage type.',
                );

                return;
            }

            $duplicate = Fine::query()
                ->where('fine_type', 'Amenity')
                ->where('amenity_id', $validated['amenityId'])
                ->where(
                    'damage_type_id',
                    $validated['damageTypeId'],
                )
                ->when(
                    $this->editingFineId !== null,
                    fn (Builder $query) => $query->where(
                        'fine_id',
                        '!=',
                        $this->editingFineId,
                    ),
                )
                ->exists();

            if ($duplicate) {
                $this->addError(
                    'amenityId',
                    'This amenity already has a fine for the selected damage type.',
                );

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
            $name = trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    (string) $validated['situationalFine'],
                )
            );

            $description = trim(
                preg_replace(
                    '/\s+/',
                    ' ',
                    (string) (
                        $validated['situationalFineDescription'] ?? ''
                    ),
                )
            );

            if ($name === '') {
                $this->addError(
                    'situationalFine',
                    'Situational fine name is required.',
                );

                return;
            }

            $duplicate = Fine::query()
                ->where('fine_type', 'Situational')
                ->whereRaw(
                    'LOWER(situational_fine) = ?',
                    [mb_strtolower($name)],
                )
                ->when(
                    $this->editingFineId !== null,
                    fn (Builder $query) => $query->where(
                        'fine_id',
                        '!=',
                        $this->editingFineId,
                    ),
                )
                ->exists();

            if ($duplicate) {
                $this->addError(
                    'situationalFine',
                    'This situational fine already exists.',
                );

                return;
            }

            $payload = [
                'fine_type' => 'Situational',
                'amenity_id' => null,
                'damage_type_id' => null,
                'situational_fine' => $name,
                'situational_fine_description' =>
                    $description !== '' ? $description : null,
                'fine_charge' => $charge,
            ];
        }

        DB::transaction(function () use ($payload): void {
            if ($this->editingFineId !== null) {
                Fine::query()
                    ->findOrFail($this->editingFineId)
                    ->update($payload);
            } else {
                Fine::query()->create($payload);
            }
        });

        session()->flash(
            'success',
            $this->editingFineId !== null
                ? 'Fine updated successfully.'
                : 'Fine created successfully.',
        );

        $this->resetFineForm();
        unset($this->fines);
    }

    public function startEditingDamageType(int $damageTypeId): void
    {
        $damageType = DamageType::query()->findOrFail($damageTypeId);

        $this->editingDamageTypeId =
            (int) $damageType->damage_type_id;
        $this->damageType = (string) $damageType->damage_type;

        $this->resetValidation();
    }

    public function resetDamageTypeForm(): void
    {
        $this->editingDamageTypeId = null;
        $this->damageType = '';

        $this->resetValidation();
    }

    public function saveDamageType(): void
    {
        $validated = $this->validate([
            'editingDamageTypeId' => [
                'nullable',
                'integer',
                'exists:tbl_damage_type,damage_type_id',
            ],
            'damageType' => ['required', 'string', 'max:50'],
        ], [
            'damageType.required' => 'Damage type is required.',
            'damageType.max' => 'Damage type must not exceed 50 characters.',
        ]);

        $name = trim(
            preg_replace('/\s+/', ' ', $validated['damageType'])
        );

        $duplicate = DamageType::query()
            ->whereRaw(
                'LOWER(damage_type) = ?',
                [mb_strtolower($name)],
            )
            ->when(
                $this->editingDamageTypeId !== null,
                fn (Builder $query) => $query->where(
                    'damage_type_id',
                    '!=',
                    $this->editingDamageTypeId,
                ),
            )
            ->exists();

        if ($duplicate) {
            $this->addError(
                'damageType',
                'This damage type already exists.',
            );

            return;
        }

        DB::transaction(function () use ($name): void {
            if ($this->editingDamageTypeId !== null) {
                DamageType::query()
                    ->findOrFail($this->editingDamageTypeId)
                    ->update([
                        'damage_type' => $name,
                    ]);
            } else {
                DamageType::query()->create([
                    'damage_type' => $name,
                ]);
            }
        });

        session()->flash(
            'success',
            $this->editingDamageTypeId !== null
                ? 'Damage type updated successfully.'
                : 'Damage type created successfully.',
        );

        $this->resetDamageTypeForm();

        unset(
            $this->damageTypes,
            $this->fines,
        );
    }

    public function sortIcon(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function fineName(Fine $fine): string
    {
        if ($fine->fine_type === 'Amenity') {
            $amenityName =
                $fine->amenity?->amenityName?->amenity_name
                ?? 'Unknown amenity';

            $description = $fine->amenity?->amenity_description;

            return filled($description)
                ? $amenityName.' — '.$description
                : $amenityName;
        }

        return $fine->situational_fine
            ?? 'Unnamed situational fine';
    }

    public function fineDescription(Fine $fine): string
    {
        if ($fine->fine_type === 'Amenity') {
            return 'Amenity-related charge applied during inspection or check-out.';
        }

        return $fine->situational_fine_description
            ?: 'No description provided.';
    }

    public function typeColor(string $type): string
    {
        return match ($type) {
            'Amenity' => 'blue',
            'Situational' => 'purple',
            default => 'zinc',
        };
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">
                Fines Management
            </h1>

            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Paginated amenity-damage and situational fine master data.
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
        This module defines charge amounts only. It does not track amenity stock.
        Existing fine records are retained because inspections and billing may
        reference them historically.
    </div>

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,2fr)_minmax(22rem,1fr)]">
        <section class="min-w-0">
            <flux:card class="overflow-hidden p-0">
                <div class="border-b border-zinc-200 p-5 dark:border-zinc-800">
                    <div>
                        <h2 class="font-semibold">Fine list</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Search, filter, sort, and paginate fine master records.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <flux:input
                            wire:model.live.debounce.300ms="search"
                            label="Search"
                            placeholder="Fine, amenity, damage type, description"
                            clearable
                            class="xl:col-span-2"
                        />

                        <flux:select
                            wire:model.live="typeFilter"
                            label="Fine type"
                        >
                            <option value="">All types</option>
                            <option value="Amenity">Amenity</option>
                            <option value="Situational">Situational</option>
                        </flux:select>

                        <flux:select
                            wire:model.live="damageTypeFilter"
                            label="Damage type"
                        >
                            <option value="">All damage types</option>

                            @foreach ($this->damageTypes as $damageTypeOption)
                                <option value="{{ $damageTypeOption->damage_type_id }}">
                                    {{ $damageTypeOption->damage_type }}
                                </option>
                            @endforeach
                        </flux:select>

                        <flux:select wire:model.live="perPage" label="Rows">
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
                    <table class="w-full min-w-[68rem] text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                            <tr>
                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('fine_name')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Fine {{ $this->sortIcon('fine_name') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('fine_type')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Type {{ $this->sortIcon('fine_type') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('damage_type')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Damage Type {{ $this->sortIcon('damage_type') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('fine_charge')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Charge {{ $this->sortIcon('fine_charge') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('usage')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Usage {{ $this->sortIcon('usage') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3 text-right">Action</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->fines as $fine)
                                <tr wire:key="fine-row-{{ $fine->fine_id }}">
                                    <td class="max-w-sm px-5 py-4">
                                        <p class="font-medium">
                                            {{ $this->fineName($fine) }}
                                        </p>

                                        <p class="mt-1 text-xs leading-5 text-zinc-500">
                                            {{ $this->fineDescription($fine) }}
                                        </p>
                                    </td>

                                    <td class="px-5 py-4">
                                        <flux:badge
                                            color="{{ $this->typeColor((string) $fine->fine_type) }}"
                                            size="sm"
                                        >
                                            {{ $fine->fine_type }}
                                        </flux:badge>
                                    </td>

                                    <td class="px-5 py-4">
                                        {{ $fine->damageType?->damage_type ?? 'N/A' }}
                                    </td>

                                    <td class="px-5 py-4 font-semibold">
                                        ₱{{ number_format((float) $fine->fine_charge, 2) }}
                                    </td>

                                    <td class="px-5 py-4">
                                        {{ $fine->guest_fines_count }}
                                        guest fine{{ (int) $fine->guest_fines_count === 1 ? '' : 's' }}
                                    </td>

                                    <td class="px-5 py-4 text-right">
                                        <flux:button
                                            type="button"
                                            wire:click="startEditingFine({{ $fine->fine_id }})"
                                            size="sm"
                                            variant="ghost"
                                        >
                                            Edit
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-5 py-12 text-center text-zinc-500">
                                        No fine matches the selected filters.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                    <p class="text-sm text-zinc-500">
                        Showing
                        {{ $this->fines->firstItem() ?? 0 }}
                        to
                        {{ $this->fines->lastItem() ?? 0 }}
                        of
                        {{ $this->fines->total() }}
                        fines
                    </p>

                    {{ $this->fines->links() }}
                </div>
            </flux:card>
        </section>

        <aside class="space-y-6">
            <flux:card>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-semibold">
                            {{ $editingFineId !== null
                                ? 'Edit fine'
                                : 'Create fine' }}
                        </h2>

                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Amenity fines are item-based; situational fines are event-based.
                        </p>
                    </div>

                    @if ($editingFineId !== null)
                        <flux:button
                            type="button"
                            wire:click="createNewFine"
                            size="sm"
                            variant="ghost"
                        >
                            New
                        </flux:button>
                    @endif
                </div>

                <form wire:submit="saveFine" class="mt-5 space-y-4">
                    <flux:select
                        wire:model.live="fineType"
                        label="Fine type"
                    >
                        <option value="Situational">Situational</option>
                        <option value="Amenity">Amenity</option>
                    </flux:select>

                    @if ($fineType === 'Amenity')
                        <flux:select
                            wire:model="amenityId"
                            label="Amenity"
                        >
                            <option value="">Select amenity</option>

                            @foreach ($this->amenities as $amenity)
                                <option value="{{ $amenity->amenity_id }}">
                                    {{ $amenity->amenityName?->amenity_name ?? 'Unnamed amenity' }}
                                    — {{ $amenity->amenity_description }}
                                </option>
                            @endforeach
                        </flux:select>

                        <flux:select
                            wire:model="damageTypeId"
                            label="Damage type"
                        >
                            <option value="">Select damage type</option>

                            @foreach ($this->damageTypes as $damageTypeOption)
                                <option value="{{ $damageTypeOption->damage_type_id }}">
                                    {{ $damageTypeOption->damage_type }}
                                </option>
                            @endforeach
                        </flux:select>
                    @else
                        <flux:input
                            wire:model="situationalFine"
                            label="Situational fine"
                            placeholder="Example: Stained Fabric"
                        />

                        <flux:input
                            wire:model="situationalFineDescription"
                            label="Description"
                            placeholder="Describe when this charge applies"
                        />
                    @endif

                    <flux:input
                        wire:model="fineCharge"
                        type="number"
                        step="0.01"
                        min="0.01"
                        label="Fine charge"
                        placeholder="200.00"
                    />

                    <div class="flex justify-end gap-3 pt-2">
                        <flux:button
                            type="button"
                            wire:click="resetFineForm"
                            variant="ghost"
                        >
                            Clear
                        </flux:button>

                        <flux:button type="submit" variant="primary">
                            {{ $editingFineId !== null
                                ? 'Save Changes'
                                : 'Create Fine' }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>

            <flux:card>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-semibold">Damage types</h2>

                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Shared classifications used by amenity fines.
                        </p>
                    </div>

                    @if ($editingDamageTypeId !== null)
                        <flux:button
                            type="button"
                            wire:click="resetDamageTypeForm"
                            size="sm"
                            variant="ghost"
                        >
                            New
                        </flux:button>
                    @endif
                </div>

                <form wire:submit="saveDamageType" class="mt-5 space-y-4">
                    <flux:input
                        wire:model="damageType"
                        label="Damage type"
                        placeholder="Damaged, Missing, Stained"
                    />

                    <div class="flex justify-end gap-3">
                        <flux:button
                            type="button"
                            wire:click="resetDamageTypeForm"
                            variant="ghost"
                        >
                            Clear
                        </flux:button>

                        <flux:button type="submit" variant="primary">
                            {{ $editingDamageTypeId !== null
                                ? 'Save Changes'
                                : 'Add Damage Type' }}
                        </flux:button>
                    </div>
                </form>

                <div class="mt-5 max-h-72 space-y-2 overflow-y-auto">
                    @forelse ($this->damageTypes as $damageTypeOption)
                        <button
                            type="button"
                            wire:click="startEditingDamageType({{ $damageTypeOption->damage_type_id }})"
                            class="flex w-full items-center justify-between rounded-lg border border-zinc-200 px-3 py-2 text-left text-sm transition hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-900"
                        >
                            <span>{{ $damageTypeOption->damage_type }}</span>

                            <span class="text-xs text-zinc-500">
                                {{ $damageTypeOption->fines_count }}
                                fine{{ (int) $damageTypeOption->fines_count === 1 ? '' : 's' }}
                            </span>
                        </button>
                    @empty
                        <p class="text-sm text-zinc-500">
                            No damage type has been configured.
                        </p>
                    @endforelse
                </div>
            </flux:card>
        </aside>
    </div>
</div>

<?php

use App\Models\Amenity;
use App\Models\Facility;
use App\Models\FacilityAmenity;
use App\Models\FacilityPrice;
use App\Models\FacilityType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Facility Management - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'sort', except: 'facility_name')]
    public string $sortField = 'facility_name';

    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $editingId = null;
    public string $facilityName = '';
    public string $facilityTypeId = '';
    public string $facilitySize = '';
    public string $capacity = '';
    public string $facilityStatus = 'Available';
    public bool $statusLocked = false;

    public array $priceRows = [
        ['rate_type' => 'Day Rate', 'facility_price' => ''],
    ];

    public array $amenityRows = [
        ['amenity_id' => '', 'amenity_quantity' => ''],
    ];

    #[Computed]
    public function facilityTypes()
    {
        return FacilityType::query()
            ->orderBy('facility_type')
            ->get();
    }

    #[Computed]
    public function amenities()
    {
        return Amenity::query()
            ->with('amenityName')
            ->get()
            ->sortBy(fn (Amenity $amenity): string => $amenity->amenityName?->amenity_name ?? '')
            ->values();
    }

    #[Computed]
    public function facilities()
    {
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
        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        return Facility::query()
            ->with([
                'facilityType',
                'prices' => fn ($query) => $query->orderBy('rate_type'),
                'facilityAmenities.amenity.amenityName',
            ])
            ->when(trim($this->search) !== '', function ($query): void {
                $search = '%'.trim($this->search).'%';

                $query->where(function ($query) use ($search): void {
                    $query->where('facility_name', 'like', $search)
                        ->orWhere('facility_size', 'like', $search)
                        ->orWhere('capacity', 'like', $search)
                        ->orWhere('facility_status', 'like', $search)
                        ->orWhereHas('facilityType', function ($query) use ($search): void {
                            $query->where('facility_type', 'like', $search);
                        });
                });
            })
            ->when($this->typeFilter !== '', function ($query): void {
                $query->where('facility_type_id', $this->typeFilter);
            })
            ->when($this->statusFilter !== '', function ($query): void {
                $query->where('facility_status', $this->statusFilter);
            })
            ->orderBy($sortField, $sortDirection)
            ->paginate($perPage);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
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
        $this->typeFilter = '';
        $this->statusFilter = '';
        $this->sortField = 'facility_name';
        $this->sortDirection = 'asc';
        $this->perPage = 10;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
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
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function createNew(): void
    {
        $this->resetForm();
    }

    public function startEditing(int $facilityId): void
    {
        $facility = Facility::query()
            ->with(['prices', 'facilityAmenities'])
            ->findOrFail($facilityId);

        $this->editingId = (int) $facility->facility_id;
        $this->facilityName = (string) $facility->facility_name;
        $this->facilityTypeId = (string) $facility->facility_type_id;
        $this->facilitySize = (string) $facility->facility_size;
        $this->capacity = (string) $facility->capacity;
        $this->facilityStatus = (string) $facility->facility_status;
        $this->statusLocked = in_array($facility->facility_status, ['Booked', 'Occupied'], true);

        $this->priceRows = $facility->prices
            ->map(fn (FacilityPrice $price): array => [
                'rate_type' => (string) $price->rate_type,
                'facility_price' => number_format((float) $price->facility_price, 2, '.', ''),
            ])
            ->values()
            ->all();

        if ($this->priceRows === []) {
            $this->priceRows = [
                ['rate_type' => 'Day Rate', 'facility_price' => ''],
            ];
        }

        $this->amenityRows = $facility->facilityAmenities
            ->map(fn (FacilityAmenity $facilityAmenity): array => [
                'amenity_id' => (string) $facilityAmenity->amenity_id,
                'amenity_quantity' => (string) $facilityAmenity->amenity_quantity,
            ])
            ->values()
            ->all();

        if ($this->amenityRows === []) {
            $this->amenityRows = [
                ['amenity_id' => '', 'amenity_quantity' => ''],
            ];
        }

        $this->resetValidation();
    }

    public function resetForm(): void
    {
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
    }

    public function addPriceRow(): void
    {
        $this->priceRows[] = [
            'rate_type' => '',
            'facility_price' => '',
        ];
    }

    public function removePriceRow(int $index): void
    {
        unset($this->priceRows[$index]);
        $this->priceRows = array_values($this->priceRows);

        if ($this->priceRows === []) {
            $this->addPriceRow();
        }
    }

    public function addAmenityRow(): void
    {
        $this->amenityRows[] = [
            'amenity_id' => '',
            'amenity_quantity' => '',
        ];
    }

    public function removeAmenityRow(int $index): void
    {
        unset($this->amenityRows[$index]);
        $this->amenityRows = array_values($this->amenityRows);

        if ($this->amenityRows === []) {
            $this->addAmenityRow();
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'editingId' => ['nullable', 'integer', 'exists:tbl_facility,facility_id'],
            'facilityName' => [
                'required',
                'string',
                'max:50',
                Rule::unique('tbl_facility', 'facility_name')
                    ->ignore($this->editingId, 'facility_id'),
            ],
            'facilityTypeId' => [
                'required',
                'integer',
                'exists:tbl_facility_type,facility_type_id',
            ],
            'facilitySize' => ['required', 'string', 'max:50'],
            'capacity' => ['required', 'string', 'max:50'],
            'facilityStatus' => [
                'required',
                Rule::in(['Available', 'Unavailable', 'Booked', 'Occupied']),
            ],
            'priceRows' => ['required', 'array', 'min:1'],
            'priceRows.*.rate_type' => ['nullable', 'string', 'max:20'],
            'priceRows.*.facility_price' => [
                'nullable',
                'numeric',
                'min:0',
                'max:999999.99',
            ],
            'amenityRows' => ['nullable', 'array'],
            'amenityRows.*.amenity_id' => [
                'nullable',
                'integer',
                'exists:tbl_amenity,amenity_id',
            ],
            'amenityRows.*.amenity_quantity' => [
                'nullable',
                'integer',
                'min:1',
                'max:9999',
            ],
        ], [
            'facilityName.required' => 'Facility name is required.',
            'facilityName.unique' => 'This facility name is already used.',
            'facilityTypeId.required' => 'Facility type is required.',
            'facilityTypeId.exists' => 'Selected facility type does not exist.',
            'facilitySize.required' => 'Facility size is required.',
            'capacity.required' => 'Capacity is required.',
            'facilityStatus.in' => 'Select a valid facility status.',
            'priceRows.required' => 'Add at least one price row.',
            'priceRows.*.facility_price.numeric' => 'Facility price must be a valid number.',
            'amenityRows.*.amenity_quantity.integer' => 'Amenity quantity must be a whole number.',
        ]);

        $cleanPrices = collect($validated['priceRows'])
            ->map(fn (array $row): array => [
                'rate_type' => trim((string) ($row['rate_type'] ?? '')),
                'facility_price' => $row['facility_price'] ?? null,
            ])
            ->filter(fn (array $row): bool => $row['rate_type'] !== '' || filled($row['facility_price']))
            ->values();

        if ($cleanPrices->isEmpty()) {
            $this->addError('priceRows', 'Add at least one valid price.');
            return;
        }

        foreach ($cleanPrices as $index => $row) {
            if ($row['rate_type'] === '' || ! filled($row['facility_price'])) {
                $this->addError(
                    'priceRows.'.$index.'.rate_type',
                    'Each price row needs a rate type and price.',
                );

                return;
            }
        }

        if ($cleanPrices->pluck('rate_type')->count() !== $cleanPrices->pluck('rate_type')->unique()->count()) {
            $this->addError('priceRows', 'Rate types must not be duplicated.');
            return;
        }

        $cleanAmenities = collect($validated['amenityRows'] ?? [])
            ->map(fn (array $row): array => [
                'amenity_id' => $row['amenity_id'] ?? null,
                'amenity_quantity' => $row['amenity_quantity'] ?? null,
            ])
            ->filter(fn (array $row): bool => filled($row['amenity_id']) || filled($row['amenity_quantity']))
            ->values();

        foreach ($cleanAmenities as $index => $row) {
            if (! filled($row['amenity_id']) || ! filled($row['amenity_quantity'])) {
                $this->addError(
                    'amenityRows.'.$index.'.amenity_id',
                    'Each amenity row needs an amenity and quantity.',
                );

                return;
            }
        }

        if ($cleanAmenities->pluck('amenity_id')->count() !== $cleanAmenities->pluck('amenity_id')->unique()->count()) {
            $this->addError('amenityRows', 'Do not add the same amenity more than once.');
            return;
        }

        $currentFacility = $this->editingId !== null
            ? Facility::query()->findOrFail($this->editingId)
            : null;

        $statusIsLocked = $currentFacility !== null
            && in_array($currentFacility->facility_status, ['Booked', 'Occupied'], true);

        if (! $statusIsLocked && ! in_array($validated['facilityStatus'], ['Available', 'Unavailable'], true)) {
            $this->addError(
                'facilityStatus',
                'Only booking and check-in workflows may set Booked or Occupied.',
            );

            return;
        }

        DB::transaction(function () use (
            $validated,
            $cleanPrices,
            $cleanAmenities,
            $currentFacility,
            $statusIsLocked,
        ): void {
            $payload = [
                'facility_name' => trim($validated['facilityName']),
                'facility_type_id' => (int) $validated['facilityTypeId'],
                'facility_size' => trim($validated['facilitySize']),
                'capacity' => trim($validated['capacity']),
                'facility_status' => $statusIsLocked
                    ? $currentFacility->facility_status
                    : $validated['facilityStatus'],
            ];

            if ($currentFacility !== null) {
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

        session()->flash(
            'success',
            $this->editingId !== null
                ? 'Facility updated successfully.'
                : 'Facility created successfully.',
        );

        $this->resetForm();
    }

    public function sortIcon(string $field): string
    {
        if ($this->sortField !== $field) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function priceSummary(Facility $facility): string
    {
        $summary = $facility->prices
            ->map(
                fn (FacilityPrice $price): string => $price->rate_type
                    .': ₱'
                    .number_format((float) $price->facility_price, 2)
            )
            ->implode(', ');

        return $summary !== '' ? $summary : 'No price set';
    }

    public function amenitySummary(Facility $facility): string
    {
        $summary = $facility->facilityAmenities
            ->map(function (FacilityAmenity $facilityAmenity): string {
                $name = $facilityAmenity->amenity?->amenityName?->amenity_name
                    ?? 'Unknown amenity';

                return $name.' × '.$facilityAmenity->amenity_quantity;
            })
            ->implode(', ');

        return $summary !== '' ? $summary : 'No inclusive amenities';
    }

    public function statusColor(string $status): string
    {
        return match ($status) {
            'Available' => 'green',
            'Unavailable' => 'red',
            'Booked' => 'blue',
            'Occupied' => 'amber',
            default => 'zinc',
        };
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Facility Management</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Paginated facility master data with prices and inclusive amenities.
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

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,2fr)_minmax(22rem,1fr)]">
        <section class="min-w-0">
            <flux:card class="overflow-hidden p-0">
                <div class="border-b border-zinc-200 p-5 dark:border-zinc-800">
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h2 class="font-semibold">Facility list</h2>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    Search, filter, sort, and paginate without loading every facility at once.
                                </p>
                            </div>

                            <div class="flex flex-wrap items-end gap-2">
                                <div class="w-28">
                                    <flux:select wire:model.live="perPage" label="Rows">
                                        <option value="10">10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                    </flux:select>
                                </div>

                                <flux:button wire:click="clearFilters" variant="ghost">
                                    Clear Filters
                                </flux:button>
                            </div>
                        </div>

                        <div class="grid gap-3 md:grid-cols-3">
                            <flux:input
                                wire:model.live.debounce.300ms="search"
                                label="Search"
                                placeholder="Name, type, size, capacity"
                                clearable
                            />

                            <flux:select wire:model.live="typeFilter" label="Type">
                                <option value="">All types</option>
                                @foreach ($this->facilityTypes as $type)
                                    <option value="{{ $type->facility_type_id }}">
                                        {{ $type->facility_type }}
                                    </option>
                                @endforeach
                            </flux:select>

                            <flux:select wire:model.live="statusFilter" label="Status">
                                <option value="">All statuses</option>
                                <option value="Available">Available</option>
                                <option value="Unavailable">Unavailable</option>
                                <option value="Booked">Booked</option>
                                <option value="Occupied">Occupied</option>
                            </flux:select>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[76rem] text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                            <tr>
                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('facility_name')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Facility {{ $this->sortIcon('facility_name') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3 font-semibold">Type</th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('facility_size')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Size {{ $this->sortIcon('facility_size') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('capacity')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Capacity {{ $this->sortIcon('capacity') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3 font-semibold">Prices</th>
                                <th class="px-5 py-3 font-semibold">Inclusive amenities</th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('facility_status')"
                                        class="inline-flex items-center gap-1 font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Status {{ $this->sortIcon('facility_status') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3 text-right font-semibold">Action</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->facilities as $facility)
                                <tr wire:key="facility-row-{{ $facility->facility_id }}">
                                    <td class="px-5 py-4 font-medium">{{ $facility->facility_name }}</td>
                                    <td class="px-5 py-4">{{ $facility->facilityType?->facility_type ?? 'Unknown' }}</td>
                                    <td class="px-5 py-4">{{ $facility->facility_size }}</td>
                                    <td class="px-5 py-4">{{ $facility->capacity }}</td>
                                    <td class="max-w-xs px-5 py-4 text-xs leading-5 text-zinc-600 dark:text-zinc-300">
                                        {{ $this->priceSummary($facility) }}
                                    </td>
                                    <td class="max-w-sm px-5 py-4 text-xs leading-5 text-zinc-600 dark:text-zinc-300">
                                        {{ $this->amenitySummary($facility) }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <flux:badge color="{{ $this->statusColor((string) $facility->facility_status) }}" size="sm">
                                            {{ $facility->facility_status }}
                                        </flux:badge>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <flux:button
                                            wire:click="startEditing({{ $facility->facility_id }})"
                                            size="sm"
                                            variant="ghost"
                                        >
                                            Edit
                                        </flux:button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-5 py-12 text-center text-zinc-500">
                                        No facility matches the current filters.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                    <p class="text-sm text-zinc-500">
                        Showing
                        {{ $this->facilities->firstItem() ?? 0 }}
                        to
                        {{ $this->facilities->lastItem() ?? 0 }}
                        of
                        {{ $this->facilities->total() }}
                        facilities
                    </p>

                    {{ $this->facilities->links() }}
                </div>
            </flux:card>
        </section>

        <aside>
            <flux:card>
                <div class="mb-5 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">
                            {{ $editingId !== null ? 'Edit facility' : 'Create facility' }}
                        </h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Operational statuses Booked and Occupied remain controlled by transaction workflows.
                        </p>
                    </div>

                    @if ($editingId !== null)
                        <flux:button wire:click="resetForm" size="sm" variant="ghost">
                            Cancel
                        </flux:button>
                    @endif
                </div>

                <form wire:submit="save" class="space-y-5">
                    <flux:input
                        wire:model="facilityName"
                        label="Facility name"
                        placeholder="Example: C300-056"
                    />

                    <flux:select wire:model="facilityTypeId" label="Facility type">
                        <option value="">Select facility type</option>
                        @foreach ($this->facilityTypes as $type)
                            <option value="{{ $type->facility_type_id }}">
                                {{ $type->facility_type }}
                            </option>
                        @endforeach
                    </flux:select>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input
                            wire:model="facilitySize"
                            label="Size / classification"
                            placeholder="Example: Small"
                        />

                        <flux:input
                            wire:model="capacity"
                            label="Capacity"
                            placeholder="Example: 4-6 guests"
                        />
                    </div>

                    <flux:select
                        wire:model="facilityStatus"
                        label="Operational availability"
                        :disabled="$statusLocked"
                    >
                        @if ($statusLocked)
                            <option value="{{ $facilityStatus }}">{{ $facilityStatus }}</option>
                        @else
                            <option value="Available">Available</option>
                            <option value="Unavailable">Unavailable</option>
                        @endif
                    </flux:select>

                    @if ($statusLocked)
                        <p class="text-xs text-amber-700 dark:text-amber-300">
                            This facility is {{ $facilityStatus }}. Booking/check-in workflows control this status.
                        </p>
                    @endif

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold">Facility prices</h3>
                                <p class="text-xs text-zinc-500">Add each supported rate type.</p>
                            </div>

                            <flux:button type="button" wire:click="addPriceRow" size="sm" variant="ghost">
                                Add Price
                            </flux:button>
                        </div>

                        @error('priceRows')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror

                        @foreach ($priceRows as $index => $row)
                            <div wire:key="price-row-{{ $index }}" class="grid gap-2 rounded-lg border border-zinc-200 p-3 sm:grid-cols-[1fr_1fr_auto] dark:border-zinc-800">
                                <flux:input
                                    wire:model="priceRows.{{ $index }}.rate_type"
                                    label="Rate type"
                                    placeholder="Day Rate"
                                />

                                <flux:input
                                    wire:model="priceRows.{{ $index }}.facility_price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    label="Price"
                                    placeholder="0.00"
                                />

                                <div class="flex items-end">
                                    <flux:button
                                        type="button"
                                        wire:click="removePriceRow({{ $index }})"
                                        size="sm"
                                        variant="ghost"
                                    >
                                        Remove
                                    </flux:button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold">Inclusive amenities</h3>
                                <p class="text-xs text-zinc-500">Items normally included in this facility.</p>
                            </div>

                            <flux:button type="button" wire:click="addAmenityRow" size="sm" variant="ghost">
                                Add Amenity
                            </flux:button>
                        </div>

                        @error('amenityRows')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror

                        @foreach ($amenityRows as $index => $row)
                            <div wire:key="amenity-row-{{ $index }}" class="grid gap-2 rounded-lg border border-zinc-200 p-3 sm:grid-cols-[1fr_8rem_auto] dark:border-zinc-800">
                                <flux:select
                                    wire:model="amenityRows.{{ $index }}.amenity_id"
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

                                <flux:input
                                    wire:model="amenityRows.{{ $index }}.amenity_quantity"
                                    type="number"
                                    min="1"
                                    label="Quantity"
                                />

                                <div class="flex items-end">
                                    <flux:button
                                        type="button"
                                        wire:click="removeAmenityRow({{ $index }})"
                                        size="sm"
                                        variant="ghost"
                                    >
                                        Remove
                                    </flux:button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <flux:button type="submit" variant="primary" class="w-full">
                        {{ $editingId !== null ? 'Save Changes' : 'Create Facility' }}
                    </flux:button>
                </form>
            </flux:card>
        </aside>
    </div>
</div>

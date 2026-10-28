<?php

use App\Models\Amenity;
use Illuminate\Validation\Rule;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\FacilityType;
use App\Models\FacilityPrice;
use App\Models\FacilityAmenity;
use App\Services\FacilityManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

new #[Layout('layouts.app')] #[Title('Facility Management - Olaer Spring Resort')] class extends Component
{

    public function updated(string $property): void
    {
        // Clear the red highlight of a field as soon as the user edits it.
        $this->resetValidation($property);
    }

    /** Error text for a required field; an empty required field only turns red (no text). */
    public function visibleError(string $field): ?string
    {
        $message = $this->getErrorBag()->first($field);

        if ($message === '') {
            return null;
        }

        $value = data_get($this, $field);

        return $value === null || $value === '' || $value === [] ? null : $message;
    }
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';
    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';
    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';
    #[Url(as: 'sort', except: 'facility_number')]
    public string $sortField = 'facility_number';
    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';
    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $editingId = null;
    public string $facilityName = '';
    public string $facilityTypeId = '';
    public string $facilitySize = '';
    public string $capacity = '';
    public string $min_capacity = '';
    public string $max_capacity = '';
    public string $facilityStatus = 'Available';
    public bool $statusLocked = false;
    public string $presetName = '';
    public string $sizeChoice = '';
    public string $originalSize = '';
    public string $productCode = '';
    public bool $applyToSize = false;
    public array $priceRows = [
    ];

    public array $amenityRows = [
        ['amenity_id' => '', 'amenity_quantity' => ''],
    ];

    public bool $showFacilityEditor = false;

    #[Computed]
    public function facilities(): LengthAwarePaginator
    {
        $sort = in_array($this->sortField, ['facility_number', 'facility_name', 'facility_type', 'facility_size', 'facility_status'], true)
            ? $this->sortField
            : 'facility_number';
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';
        $perPage = in_array($this->perPage, [10, 25, 50, 100], true) ? $this->perPage : 10;

        return Facility::query()
            ->with(['facilityType', 'facilityProduct'])
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $like = '%'.trim($this->search).'%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->where('facility_number', 'like', $like)
                        ->orWhere('facility_name', 'like', $like)
                        ->orWhere('min_capacity', 'like', $like)
                        ->orWhere('max_capacity', 'like', $like)
                        ->orWhereIn(
                            'facility_product_id',
                            FacilityProduct::query()->select('facility_product_id')->where('display_name', 'like', $like),
                        );
                });
            })
            ->when($this->typeFilter !== '', fn (Builder $query) => $query->where('facility_type_id', $this->typeFilter))
            ->when($this->statusFilter !== '', fn (Builder $query) => $query->where('facility_status', $this->statusFilter))
            ->when(
                $sort === 'facility_type',
                fn (Builder $query) => $query->orderBy(
                    FacilityType::query()
                        ->select('facility_type')
                        ->whereColumn('tbl_facility_type.facility_type_id', 'tbl_facility.facility_type_id'),
                    $direction,
                ),
                fn (Builder $query) => $query->orderBy($sort, $direction),
            )
            ->orderBy('facility_id')
            ->paginate($perPage);
    }

    #[Computed]
    public function facilityTypeGetter(){
        return FacilityType::query()
            ->where('facility_type_id', $this->facilityTypeId)
            ->value('facility_type');
    }

    public function addAmenityRow(): void
    {
        $this->amenityRows[] = [
            'amenity_id' => '',
            'amenity_quantity' => '',
        ];
    }

    /** @return array<string, array{size: string, count: int, min: int, max: int, product_code: ?string, prices: array<string, string>}> */
    #[Computed]
    public function sizeProfiles(): array
    {
        if ($this->facilityTypeId === '') {
            return [];
        }

        return app(FacilityManagementService::class)->sizeProfiles((int) $this->facilityTypeId);
    }

    /** The shared size profile that the chosen size belongs to, if it already exists. */
    public function selectedProfile(): ?array
    {
        if ($this->sizeChoice === '' || $this->sizeChoice === '__new__') {
            return null;
        }

        return $this->sizeProfiles()[$this->sizeChoice] ?? null;
    }

    /** Capacity and prices are shared by every facility of a size, so lock capacity unless this is the only one. */
    public function capacityLocked(): bool
    {
        $profile = $this->selectedProfile();

        if ($profile === null) {
            return false;
        }

        return ! $this->isOnlyFacilityOfOwnSize($profile);
    }

    /** True when editing the single facility that defines its own size, so nothing else shares its capacity or prices. */
    private function isOnlyFacilityOfOwnSize(array $profile): bool
    {
        return $this->editingId !== null
            && $this->sizeChoice === $this->originalSize
            && $profile['count'] <= 1;
    }

    public function pricesDifferFromSize(): bool
    {
        $profile = $this->selectedProfile();

        if ($profile === null || $this->isOnlyFacilityOfOwnSize($profile)) {
            return false;
        }

        foreach ($this->priceRows as $row) {
            if (! is_numeric($row['facility_price'] ?? null)) {
                continue;
            }

            $current = $profile['prices'][$row['rate_type']] ?? null;

            if ($current !== null && number_format((float) $row['facility_price'], 2, '.', '') !== $current) {
                return true;
            }
        }

        return false;
    }

    public function addPriceRow(): void
    {
        $type = $this->facilityTypeGetter();

        $this->priceRows = collect(
            app(FacilityManagementService::class)->rateTypesFor($type),
        )->map(fn (string $rateType): array => [
            'rate_type' => $rateType,
            'facility_price' => '',
        ])->all();
    }

    public function removeAmenityRow(int $index): void
    {
        unset($this->amenityRows[$index]);
        $this->amenityRows = array_values($this->amenityRows);

        if ($this->amenityRows === []) {
            $this->addAmenityRow();
        }
    }

    public function updatedFacilityTypeId(): void
    {
        unset($this->sizeProfiles, $this->facilityTypeGetter);

        $this->sizeChoice = '';
        $this->facilitySize = '';
        $this->productCode = '';
        $this->applyToSize = false;
        $this->min_capacity = '';
        $this->max_capacity = '';
        $this->addPriceRow();
    }

    public function updatedSizeChoice(): void
    {
        $this->applyToSize = false;
        $this->resetValidation();

        $profile = $this->selectedProfile();

        if ($profile === null) {
            // "Create new size" (or nothing chosen): start from a clean slate.
            $this->facilitySize = '';
            $this->productCode = '';
            $this->min_capacity = '';
            $this->max_capacity = '';
            $this->addPriceRow();

            return;
        }

        $this->facilitySize = $profile['size'];
        $this->productCode = (string) $profile['product_code'];
        $this->min_capacity = (string) $profile['min'];
        $this->max_capacity = (string) $profile['max'];
        $this->fillPriceRowsFromProfile($profile);
    }

    /** @param array{prices: array<string, string>} $profile */
    private function fillPriceRowsFromProfile(array $profile): void
    {
        $this->addPriceRow();

        $this->priceRows = collect($this->priceRows)
            ->map(fn (array $row): array => [
                'rate_type' => $row['rate_type'],
                'facility_price' => $profile['prices'][$row['rate_type']] ?? '',
            ])
            ->all();
    }

    public function save(FacilityManagementService $facilities): void
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
            'sizeChoice' => ['required', 'string'],
            'facilitySize' => ['required', 'string', 'max:50'],
            'min_capacity' => ['required', 'integer', 'min:1', 'max:9999'],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:9999', 'gte:min_capacity'],
            'facilityStatus' => [
                'required',
                Rule::in(['Available', 'Unavailable', 'Booked', 'Occupied']),
            ],
            'applyToSize' => ['boolean'],
            'priceRows' => ['required', 'array', 'min:1'],
            'priceRows.*.rate_type' => ['required', 'string', 'max:20'],
            'priceRows.*.facility_price' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
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
            'facilityName.required' => 'Facility number is required.',
            'facilityName.unique' => 'This facility number is already used.',
            'facilityTypeId.required' => 'Facility type is required.',
            'facilityTypeId.exists' => 'Selected facility type does not exist.',
            'sizeChoice.required' => 'Choose a facility name or create a new one.',
            'facilitySize.required' => 'Facility name is required.',
            'min_capacity.required' => 'Minimum capacity is required.',
            'max_capacity.required' => 'Maximum capacity is required.',
            'max_capacity.gte' => 'Maximum capacity must be at least the minimum capacity.',
            'facilityStatus.in' => 'Select a valid facility status.',
            'priceRows.required' => 'Select a facility type first.',
            'priceRows.*.facility_price.required' => 'Enter a price for every rate.',
            'priceRows.*.facility_price.numeric' => 'Facility price must be a valid number.',
            'priceRows.*.facility_price.gt' => 'Facility price must be greater than zero.',
            'amenityRows.*.amenity_quantity.integer' => 'Amenity quantity must be a whole number.',
        ]);

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

        try {
            $facilities->saveFacility($currentFacility, [
                'facility_name' => $validated['facilityName'],
                'facility_type_id' => (int) $validated['facilityTypeId'],
                'facility_size' => $validated['facilitySize'],
                'min_capacity' => (int) $validated['min_capacity'],
                'max_capacity' => (int) $validated['max_capacity'],
                'facility_status' => $statusIsLocked
                    ? $currentFacility->facility_status
                    : $validated['facilityStatus'],
                'prices' => collect($validated['priceRows'])
                    ->mapWithKeys(fn (array $row): array => [$row['rate_type'] => $row['facility_price']])
                    ->all(),
                'amenities' => $cleanAmenities->all(),
                'apply_to_size' => (bool) $validated['applyToSize'],
            ]);
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage();

            $field = match (true) {
                str_contains($message, 'price') || str_contains($message, 'Price') => 'priceRows',
                str_contains($message, 'apacity') => 'max_capacity',
                default => 'facilityName',
            };

            $this->addError($field, $message);

            return;
        }

        session()->flash(
            'success',
            $this->editingId !== null
                ? 'Facility updated successfully.'
                : 'Facility created successfully.',
        );
        $this->showFacilityEditor = false;

        $this->resetFacilityForm();
    }

    #[Computed]
    public function facilityTypes(): Collection
    {
        return FacilityType::query()->orderBy('facility_type')->get();
    }

    public function createNewFacility(): void
    {
        $this->resetFacilityForm();
        $this->showFacilityEditor = true;
    }
    
    public function cancelFacilityEdit(): void
    {
        session()->flash('success', 'Facility edit cancelled. No changes were saved.');
        $this->showFacilityEditor = false;
        $this->resetFacilityForm();
    }

    public function startEditing(int $facilityId): void
    {
        $facility = Facility::query()
            ->with(['prices', 'facilityAmenities', 'facilityProduct'])
            ->findOrFail($facilityId);

        $existingPrices = $facility->prices
            ->keyBy(fn (FacilityPrice $price): string => (string) $price->rate_type);
        

        $this->showFacilityEditor = true;

        $this->editingId = (int) $facility->facility_id;
        $this->facilityName = (string) $facility->facility_name;
        $this->facilityTypeId = (string) $facility->facility_type_id;
        $this->facilitySize = (string) $facility->facility_size;
        $this->min_capacity = (string) $facility->min_capacity;
        $this->max_capacity = (string) $facility->max_capacity;
        $this->facilityStatus = (string) $facility->facility_status;
        $this->statusLocked = in_array($facility->facility_status, ['Booked', 'Occupied'], true);
        unset($this->sizeProfiles, $this->facilityTypeGetter);
        $this->sizeChoice = (string) $facility->facility_size;
        $this->originalSize = (string) $facility->facility_size;
        $this->productCode = (string) $facility->facilityProduct?->product_code;
        $this->applyToSize = false;
        $expectedRates = app(FacilityManagementService::class)
            ->rateTypesFor($this->facilityTypeGetter());
        $this->priceRows = collect($expectedRates)
            ->map(function (string $rateType) use ($existingPrices): array {
                $existing = $existingPrices->get($rateType);

            return [
                'rate_type' => $rateType,
                'facility_price' => $existing !== null 
                    ? number_format((float) $existing->facility_price, 2, '.', '') 
                    : '',
            ];
        })
    ->values()
    ->all();

        if ($this->priceRows === []) {
            $this->priceRows = [
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
    
    #[Computed]
    public function amenities()
    {
        return Amenity::query()
            ->with('amenityName')
            ->get()
            ->sortBy(fn (Amenity $amenity): string => $amenity->amenityName?->amenity_name ?? '')
            ->values();
    }
    public function cloneFacility(int $facilityId): void
    {
        $this->editingId = null;
        $facility = Facility::query()
            ->with(['prices', 'facilityAmenities', 'facilityProduct'])
            ->findOrFail($facilityId);
        $existingPrices = $facility->prices
            ->keyBy(fn (FacilityPrice $price): string => (string) $price->rate_type);

        $this->showFacilityEditor = true;
        $this->facilityName = '';
        $this->facilityTypeId = (string) $facility->facility_type_id;
        $this->facilitySize = (string) $facility->facility_size;
        $this->min_capacity = (string) $facility->min_capacity;
        $this->max_capacity = (string) $facility->max_capacity;
        $this->facilityStatus = 'Available';
        unset($this->sizeProfiles, $this->facilityTypeGetter);
        $this->sizeChoice = (string) $facility->facility_size;
        $this->productCode = (string) $facility->facilityProduct?->product_code;
        $this->applyToSize = false;
        $expectedRates = app(FacilityManagementService::class)
            ->rateTypesFor($this->facilityTypeGetter());
        $this->priceRows = collect($expectedRates)
            ->map(function (string $rateType) use ($existingPrices): array {
                $existing = $existingPrices->get($rateType);

            return [
                'rate_type' => $rateType,
                'facility_price' => $existing !== null 
                    ? number_format((float) $existing->facility_price, 2, '.', '') 
                    : '',
            ];
        })
    ->values()
    ->all();

        if ($this->priceRows === []) {
            $this->priceRows = [
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

    public function resetFacilityForm(): void
    {
        $this->editingId = null;
        $this->facilityName = '';
        $this->facilityTypeId = '';
        $this->facilitySize = '';
        $this->sizeChoice = '';
        $this->originalSize = '';
        $this->productCode = '';
        $this->applyToSize = false;
        $this->min_capacity = '';
        $this->max_capacity = '';
        $this->facilityStatus = 'Available';
        $this->statusLocked = false;

        $this->priceRows = [];

        $this->amenityRows = [
            ['amenity_id' => '', 'amenity_quantity' => ''],
        ];

        $this->resetValidation();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedTypeFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->resetPage(); }

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['facility_number', 'facility_name', 'facility_type', 'facility_size', 'facility_status'], true)) {
            return;
        }

        $this->sortDirection = $this->sortField === $field && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortField = $field;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'typeFilter', 'statusFilter']);
        $this->resetPage();
    }

    public function sortIcon(string $field): string
    {
        return $this->sortField !== $field ? '↕' : ($this->sortDirection === 'asc' ? '↑' : '↓');
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
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Physical facilities use numeric capacity guidance and normalized product/rate configuration.</p>
        </div>
        <div class="flex gap-2">
            <flux:button type="button" wire:click="createNewFacility" variant="primary">Create Facility</flux:button>
            <flux:button href="{{ route('admin.dashboard') }}" wire:navigate variant="ghost">Back to Dashboard</flux:button>
        </div>
    </div>

    @if (session('success'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950/40 dark:text-green-100">{{ session('success') }}</div>
    @endif

    <flux:card class="overflow-hidden p-0">
        <div class="grid gap-3 border-b border-zinc-200 p-5 md:grid-cols-4 dark:border-zinc-800">
            <flux:input wire:model.live.debounce.300ms="search" label="Search" placeholder="Number, name, category" clearable />
            <flux:select wire:model.live="typeFilter" label="Type">
                <option value="">All types</option>
                @foreach ($this->facilityTypes as $type)
                    <option value="{{ $type->facility_type_id }}">{{ $type->facility_type }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="statusFilter" label="Status">
                <option value="">All statuses</option>
                @foreach (['Available', 'Unavailable', 'Booked', 'Occupied'] as $status)
                    <option value="{{ $status }}">{{ $status }}</option>
                @endforeach
            </flux:select>
            <div class="flex items-end gap-2">
                <flux:select wire:model.live="perPage" label="Rows">
                    @foreach ([10, 25, 50, 100] as $size)
                        <option value="{{ $size }}">{{ $size }}</option>
                    @endforeach
                </flux:select>
                <flux:button wire:click="clearFilters" variant="ghost">Clear Filters</flux:button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[62rem] text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('facility_number')" class="font-semibold">NUMBER {{ $this->sortIcon('facility_number') }}</button></th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('facility_type')" class="font-semibold">TYPE {{ $this->sortIcon('facility_type') }}</button></th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('facility_name')" class="font-semibold">FACILITY NAME {{ $this->sortIcon('facility_name') }}</button></th>
                        <th class="px-5 py-3 font-semibold">Capacity</th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('facility_status')" class="font-semibold">STATUS {{ $this->sortIcon('facility_status') }}</button></th>
                        <th class="px-5 py-3 text-right font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->facilities as $facility)
                        <tr wire:key="facility-{{ $facility->facility_id }}"  class="{{ $editingId === (int) $facility->facility_id ? 'bg-blue-50 ring-1 ring-inset ring-blue-200 dark:bg-blue-950/30 dark:ring-blue-800' : ''}} hover:bg-brand-surface-muted/70 dark:hover:bg-zinc-800/50">
                            <td class="px-5 py-4 font-medium">{{ $facility->facility_name }}</td>
                            <td class="px-5 py-4">{{ $facility->facilityType?->facility_type }}</td>
                            <td class="px-5 py-4">{{ $facility->facilityProduct?->display_name ?? $facility->facility_size }}</td>
                            <td class="px-5 py-4 text-left">{{ $facility->facilityProduct?->strict_maximum ? ''.$facility->min_capacity.'–'.$facility->max_capacity : ''.$facility->min_capacity.'–'.$facility->max_capacity }}</td>
                            <td class="px-5 py-4"><flux:badge color="{{ $this->statusColor($facility->facility_status) }}">{{ $facility->facility_status }}</flux:badge></td>
                            <td class="px-5 py-4 text-right">
                                <x-table-actions label="Actions for {{ $facility->facility_name }}">
                                    <flux:menu.item icon="pencil-square" wire:click="startEditing({{ $facility->facility_id }})">Edit facility</flux:menu.item>
                                    <flux:menu.item icon="document-duplicate" wire:click="cloneFacility({{ $facility->facility_id }})">Clone facility</flux:menu.item>
                                </x-table-actions>  
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-12 text-center text-zinc-500">No facility matches the filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800">{{ $this->facilities->links() }}</div>
    </flux:card>
    <flux:modal wire:model="showFacilityEditor" class="md:w-[38rem] max-h-[90vh] overflow-y-auto">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-semibold">
                            {{ $editingId !== null ? 'Edit facility' : 'Create facility' }}
                        </h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Operational statuses Booked and Occupied remain controlled by transaction workflows.
                        </p>
                    </div>
                </div>

                <form wire:submit="save" novalidate class="space-y-5">
                    <flux:field>
                        <flux:label>Facility number <span class="text-red-500">*</span></flux:label>
                        <flux:input wire:model="facilityName" placeholder="Example: C300-056" :invalid="$errors->has('facilityName')" />
                        @if ($msg = $this->visibleError('facilityName'))
                            <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                        @endif
                    </flux:field>

                    <flux:field>
                        <flux:label>Facility type <span class="text-red-500">*</span></flux:label>
                        <flux:select wire:model.live="facilityTypeId" :invalid="$errors->has('facilityTypeId')">
                        <flux:select.option value="">Select facility type</flux:select.option>
                            @foreach ($this->facilityTypes as $type)
                                <flux:select.option value="{{ $type->facility_type_id }}">
                                    {{ $type->facility_type }}
                        </flux:select.option>
                            @endforeach
                    </flux:select>
                        @if ($msg = $this->visibleError('facilityTypeId'))
                            <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                        @endif
                    </flux:field>

                    @php
                        $profile = $this->selectedProfile();
                        $capacityLocked = $this->capacityLocked();
                        $creatingNewSize = $sizeChoice === '__new__';
                    @endphp

                    <div class="grid gap-4">
                        <flux:field>
                            <flux:label>Facility name <span class="text-red-500">*</span></flux:label>
                            <flux:select wire:model.live="sizeChoice" :disabled="$facilityTypeId === ''" :invalid="$errors->has('sizeChoice')">
                            <flux:select.option value="">
                                {{ $facilityTypeId === '' ? 'Select facility type first' : 'Select facility name' }}
                            </flux:select.option>
                            @foreach ($this->sizeProfiles as $sizeProfile)
                                <flux:select.option value="{{ $sizeProfile['size'] }}">
                                    {{ $sizeProfile['size'] }} ({{ $sizeProfile['min'] }}–{{ $sizeProfile['max'] }} guests)
                                </flux:select.option>
                            @endforeach
                            @if ($facilityTypeId !== '')
                                <flux:select.option value="__new__">+ Create new facility name</flux:select.option>
                            @endif
                        </flux:select>
                            @if ($msg = $this->visibleError('sizeChoice'))
                                <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>


                        @if ($creatingNewSize)
                            <flux:field>
                                <flux:label>New facility name <span class="text-red-500">*</span></flux:label>
                                <flux:input wire:model="facilitySize" placeholder="Example: Jumbo Cottage" :invalid="$errors->has('facilitySize')" />
                                @if ($msg = $this->visibleError('facilitySize'))
                                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                                @endif
                            </flux:field>

                            <p class="text-xs text-zinc-500">
                                A new facility product, its product code and its rates are created automatically with this facility.
                            </p>
                        @elseif ($profile !== null)
                            <p class="text-xs text-zinc-500">
                                Product code: <span class="font-medium">{{ $productCode ?: 'not set' }}</span>.
                                @if ($capacityLocked)
                                    Capacity and prices are shared with the other {{ $profile['size'] }} facilities.
                                @endif
                            </p>
                        @endif
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>Minimum Capacity <span class="text-red-500">*</span></flux:label>
                            <flux:input wire:model="min_capacity" placeholder="Example: 1" type="number" min="1" :readonly="$capacityLocked" :invalid="$errors->has('min_capacity')" />
                            @if ($msg = $this->visibleError('min_capacity'))
                                <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>

                        <flux:field>
                            <flux:label>Maximum Capacity <span class="text-red-500">*</span></flux:label>
                            <flux:input wire:model="max_capacity" placeholder="Example: 25" type="number" min="1" :readonly="$capacityLocked" :invalid="$errors->has('max_capacity')" />
                            @if ($msg = $this->visibleError('max_capacity'))
                                <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                            @endif
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Operational availability <span class="text-red-500">*</span></flux:label>
                        <flux:select wire:model="facilityStatus" :disabled="$statusLocked" :invalid="$errors->has('facilityStatus')">
                        @if ($statusLocked)
                            <option value="{{ $facilityStatus }}">{{ $facilityStatus }}</option>
                        @else
                            <option value="Available">Available</option>
                            <option value="Unavailable">Unavailable</option>
                        @endif
                    </flux:select>
                        @if ($msg = $this->visibleError('facilityStatus'))
                            <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                        @endif
                    </flux:field>

                    @if ($statusLocked)
                        <p class="text-xs text-amber-700 dark:text-amber-300">
                            This facility is {{ $facilityStatus }}. Booking/check-in workflows control this status.
                        </p>
                    @endif

                    <div class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold">Facility prices</h3>
                                <p class="text-xs text-zinc-500">Cottages and function halls use Day, Night and Both rates. Rooms use Overnight.</p>
                            </div>
                        </div>

                        <h3 class="text-sm font-semibold">Rate type:</h3>
                        @foreach ($priceRows as $index => $row)
        <div wire:key="price-row-{{ $index }}" class="grid items-center gap-3 rounded-lg border border-zinc-200 p-3 sm:grid-cols-[1fr_1fr] dark:border-zinc-800">
            <div>
                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                    {{ $row['rate_type'] ?: 'Standard Rate' }}
                </span>
            </div>

            <!-- Price Input -->
            <flux:field>
                <flux:label>Price <span class="text-red-500">*</span></flux:label>
                <flux:input wire:model="priceRows.{{ $index }}.facility_price" type="number" step="0.01" min="0" placeholder="0.00" :invalid="$errors->has('priceRows.'.$index.'.facility_price')" />
                @if ($msg = $this->visibleError('priceRows.'.$index.'.facility_price'))
                    <p class="mt-2 text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                @endif
            </flux:field>
        </div>
    @endforeach


                        @if ($this->pricesDifferFromSize())
                            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                                <p>These prices differ from the current {{ $facilitySize }} prices. Facilities of the same size must share the same price per rate.</p>
                                <label class="mt-2 flex items-start gap-2">
                                    <input type="checkbox" wire:model.live="applyToSize" class="mt-1 rounded border-zinc-300" />
                                    <span>Change the price of every {{ $facilitySize }} facility to these prices.</span>
                                </label>
                            </div>
                        @endif
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
                    <div class="flex justify-end gap-3 pt-2">
                        <flux:button wire:click="cancelFacilityEdit" type="button" variant="ghost">
                            Cancel
                        </flux:button>
                        <flux:button type="submit" variant="primary">
                            {{ $editingId !== null ? 'Save Changes' : 'Save Facility' }}
                        </flux:button>
                    </div>
                </form>
        </flux:modal>
</div>
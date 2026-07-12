<?php

use App\Models\Amenity;
use App\Models\AmenityName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Amenity Management - Olaer Spring Resort')] class extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'type', except: '')]
    public string $typeFilter = '';

    #[Url(as: 'sort', except: 'amenity_name')]
    public string $sortField = 'amenity_name';

    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';

    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    public ?int $editingId = null;
    public string $amenityName = '';
    public string $amenityDescription = '';
    public string $amenityType = 'Rentable';
    public string $amenityPrice = '';

    #[Computed]
    public function amenities(): LengthAwarePaginator
    {
        $allowedSorts = [
            'amenity_name',
            'amenity_description',
            'amenity_type',
            'amenity_price',
            'usage',
        ];

        $sortField = in_array($this->sortField, $allowedSorts, true)
            ? $this->sortField
            : 'amenity_name';

        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        $perPage = in_array($this->perPage, [10, 25, 50, 100], true)
            ? $this->perPage
            : 10;

        $query = Amenity::query()
            ->with('amenityName')
            ->withCount([
                'facilityAmenities',
                'amenityRequestDetails',
                'fines',
            ])
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $like = '%'.trim($this->search).'%';

                $query->where(function (Builder $query) use ($like): void {
                    $query->where('amenity_description', 'like', $like)
                        ->orWhere('amenity_type', 'like', $like)
                        ->orWhere('amenity_price', 'like', $like)
                        ->orWhereHas('amenityName', function (Builder $query) use ($like): void {
                            $query->where('amenity_name', 'like', $like);
                        });
                });
            })
            ->when(
                $this->typeFilter !== '',
                fn (Builder $query) => $query->where(
                    'amenity_type',
                    $this->typeFilter,
                ),
            );

        match ($sortField) {
            'amenity_description' => $query->orderBy(
                'amenity_description',
                $direction,
            ),
            'amenity_type' => $query
                ->orderBy('amenity_type', $direction)
                ->orderBy('amenity_description'),
            'amenity_price' => $query->orderBy(
                'amenity_price',
                $direction,
            ),
            'usage' => $query->orderByRaw(
                '(facility_amenities_count + amenity_request_details_count + fines_count) '
                .$direction
            ),
            default => $query->orderBy(
                AmenityName::query()
                    ->select('amenity_name')
                    ->whereColumn(
                        'tbl_amenity_name.amenity_name_id',
                        'tbl_amenity.amenity_name_id',
                    ),
                $direction,
            ),
        };

        return $query
            ->orderBy('amenity_id')
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

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 10;
        }

        $this->resetPage();
    }

    public function updatedAmenityType(string $type): void
    {
        if ($type === 'Inclusive') {
            $this->amenityPrice = '0.00';
        }
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->typeFilter = '';
        $this->sortField = 'amenity_name';
        $this->sortDirection = 'asc';
        $this->perPage = 10;

        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
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
            $this->sortDirection = $this->sortDirection === 'asc'
                ? 'desc'
                : 'asc';
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

    public function startEditing(int $amenityId): void
    {
        $amenity = Amenity::query()
            ->with('amenityName')
            ->findOrFail($amenityId);

        $this->editingId = (int) $amenity->amenity_id;
        $this->amenityName = (string) (
            $amenity->amenityName?->amenity_name
            ?? ''
        );
        $this->amenityDescription = (string) $amenity->amenity_description;
        $this->amenityType = (string) $amenity->amenity_type;
        $this->amenityPrice = number_format(
            (float) $amenity->amenity_price,
            2,
            '.',
            '',
        );

        $this->resetValidation();
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->amenityName = '';
        $this->amenityDescription = '';
        $this->amenityType = 'Rentable';
        $this->amenityPrice = '';

        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'editingId' => [
                'nullable',
                'integer',
                'exists:tbl_amenity,amenity_id',
            ],
            'amenityName' => ['required', 'string', 'max:50'],
            'amenityDescription' => ['required', 'string', 'max:50'],
            'amenityType' => [
                'required',
                Rule::in(['Rentable', 'Inclusive']),
            ],
            'amenityPrice' => [
                'nullable',
                'numeric',
                'min:0',
                'max:999999.99',
            ],
        ], [
            'amenityName.required' => 'Amenity name is required.',
            'amenityName.max' => 'Amenity name must not exceed 50 characters.',
            'amenityDescription.required' => 'Amenity description is required.',
            'amenityDescription.max' => 'Amenity description must not exceed 50 characters.',
            'amenityType.in' => 'Amenity type must be Rentable or Inclusive.',
            'amenityPrice.numeric' => 'Amenity price must be a valid amount.',
        ]);

        $name = trim(
            preg_replace('/\s+/', ' ', $validated['amenityName'])
        );

        $description = trim(
            preg_replace('/\s+/', ' ', $validated['amenityDescription'])
        );

        $type = $validated['amenityType'];

        $price = $type === 'Inclusive'
            ? 0.00
            : round((float) ($validated['amenityPrice'] ?? 0), 2);

        if ($type === 'Rentable' && $price <= 0) {
            $this->addError(
                'amenityPrice',
                'Rentable amenities must have a price greater than zero because they are billed to guests.',
            );

            return;
        }

        DB::transaction(function () use (
            $name,
            $description,
            $type,
            $price,
        ): void {
            $amenityName = AmenityName::query()
                ->whereRaw(
                    'LOWER(amenity_name) = ?',
                    [mb_strtolower($name)],
                )
                ->first();

            if ($amenityName === null) {
                $amenityName = AmenityName::query()->create([
                    'amenity_name' => $name,
                ]);
            }

            $duplicate = Amenity::query()
                ->where(
                    'amenity_name_id',
                    $amenityName->amenity_name_id,
                )
                ->where('amenity_description', $description)
                ->where('amenity_type', $type)
                ->when(
                    $this->editingId !== null,
                    fn (Builder $query) => $query->where(
                        'amenity_id',
                        '!=',
                        $this->editingId,
                    ),
                )
                ->exists();

            if ($duplicate) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amenityName' => 'This amenity already exists with the same name, description, and type.',
                ]);
            }

            $payload = [
                'amenity_name_id' => (int) $amenityName->amenity_name_id,
                'amenity_description' => $description,
                'amenity_type' => $type,
                'amenity_price' => $price,
            ];

            if ($this->editingId !== null) {
                Amenity::query()
                    ->findOrFail($this->editingId)
                    ->update($payload);
            } else {
                Amenity::query()->create($payload);
            }
        });

        session()->flash(
            'success',
            $this->editingId !== null
                ? 'Amenity updated successfully.'
                : 'Amenity created successfully.',
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

    public function typeColor(string $type): string
    {
        return match ($type) {
            'Rentable' => 'blue',
            'Inclusive' => 'green',
            default => 'zinc',
        };
    }

    public function usageCount(Amenity $amenity): int
    {
        return (int) $amenity->facility_amenities_count
            + (int) $amenity->amenity_request_details_count
            + (int) $amenity->fines_count;
    }

    public function usageSummary(Amenity $amenity): string
    {
        $parts = [];

        if ((int) $amenity->facility_amenities_count > 0) {
            $count = (int) $amenity->facility_amenities_count;
            $parts[] = $count.' facility link'.($count === 1 ? '' : 's');
        }

        if ((int) $amenity->amenity_request_details_count > 0) {
            $count = (int) $amenity->amenity_request_details_count;
            $parts[] = $count.' request detail'.($count === 1 ? '' : 's');
        }

        if ((int) $amenity->fines_count > 0) {
            $count = (int) $amenity->fines_count;
            $parts[] = $count.' fine link'.($count === 1 ? '' : 's');
        }

        return $parts === []
            ? 'Not linked yet'
            : implode(', ', $parts);
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">
                Amenity Management
            </h1>

            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Paginated rentable and inclusive amenity master data.
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
        Inclusive amenities are included with facilities and are saved at ₱0.00.
        Rentable amenities require a positive price because amenity requests add
        that price to the guest's bill.
    </div>

    <div class="grid gap-6 2xl:grid-cols-[minmax(0,2fr)_minmax(22rem,1fr)]">
        <section class="min-w-0">
            <flux:card class="overflow-hidden p-0">
                <div class="border-b border-zinc-200 p-5 dark:border-zinc-800">
                    <div>
                        <h2 class="font-semibold">Amenity list</h2>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Search, filter, sort, and paginate without loading every amenity at once.
                        </p>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <flux:input
                            wire:model.live.debounce.300ms="search"
                            label="Search"
                            placeholder="Name, description, type, or price"
                            clearable
                        />

                        <flux:select wire:model.live="typeFilter" label="Type">
                            <option value="">All types</option>
                            <option value="Rentable">Rentable</option>
                            <option value="Inclusive">Inclusive</option>
                        </flux:select>

                        <flux:select wire:model.live="perPage" label="Rows">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </flux:select>

                        <div class="flex items-end">
                            <flux:button
                                wire:click="clearFilters"
                                variant="ghost"
                                class="w-full"
                            >
                                Clear Filters
                            </flux:button>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[68rem] text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                            <tr>
                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('amenity_name')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Amenity {{ $this->sortIcon('amenity_name') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('amenity_description')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Description {{ $this->sortIcon('amenity_description') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('amenity_type')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Type {{ $this->sortIcon('amenity_type') }}
                                    </button>
                                </th>

                                <th class="px-5 py-3">
                                    <button
                                        type="button"
                                        wire:click="sortBy('amenity_price')"
                                        class="font-semibold hover:text-zinc-950 dark:hover:text-white"
                                    >
                                        Price {{ $this->sortIcon('amenity_price') }}
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
                            @forelse ($this->amenities as $amenity)
                                <tr wire:key="amenity-row-{{ $amenity->amenity_id }}">
                                    <td class="px-5 py-4 font-medium">
                                        {{ $amenity->amenityName?->amenity_name ?? 'Unnamed amenity' }}
                                    </td>

                                    <td class="px-5 py-4">
                                        {{ $amenity->amenity_description }}
                                    </td>

                                    <td class="px-5 py-4">
                                        <flux:badge
                                            color="{{ $this->typeColor((string) $amenity->amenity_type) }}"
                                            size="sm"
                                        >
                                            {{ $amenity->amenity_type }}
                                        </flux:badge>
                                    </td>

                                    <td class="px-5 py-4 font-medium">
                                        ₱{{ number_format((float) $amenity->amenity_price, 2) }}
                                    </td>

                                    <td class="max-w-sm px-5 py-4">
                                        <p class="font-medium">
                                            {{ $this->usageCount($amenity) }} total link(s)
                                        </p>

                                        <p class="mt-1 text-xs leading-5 text-zinc-500">
                                            {{ $this->usageSummary($amenity) }}
                                        </p>
                                    </td>

                                    <td class="px-5 py-4 text-right">
                                        <flux:button
                                            type="button"
                                            wire:click="startEditing({{ $amenity->amenity_id }})"
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
                                        No amenity matches the selected filters.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col gap-3 border-t border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
                    <p class="text-sm text-zinc-500">
                        Showing
                        {{ $this->amenities->firstItem() ?? 0 }}
                        to
                        {{ $this->amenities->lastItem() ?? 0 }}
                        of
                        {{ $this->amenities->total() }}
                        amenities
                    </p>

                    {{ $this->amenities->links() }}
                </div>
            </flux:card>
        </section>

        <aside>
            <flux:card>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-semibold">
                            {{ $editingId !== null
                                ? 'Edit amenity'
                                : 'Create amenity' }}
                        </h2>

                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            Labels appear in facilities, amenity requests, fines, inspections, and reports.
                        </p>
                    </div>

                    @if ($editingId !== null)
                        <flux:button
                            type="button"
                            wire:click="createNew"
                            size="sm"
                            variant="ghost"
                        >
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

                    <flux:select
                        wire:model.live="amenityType"
                        label="Amenity type"
                    >
                        <option value="Rentable">Rentable</option>
                        <option value="Inclusive">Inclusive</option>
                    </flux:select>

                    <flux:input
                        wire:model="amenityPrice"
                        type="number"
                        step="0.01"
                        min="0"
                        label="Price"
                        placeholder="0.00"
                        :readonly="$amenityType === 'Inclusive'"
                    />

                    @if ($amenityType === 'Inclusive')
                        <p class="-mt-2 text-xs text-zinc-500">
                            Inclusive amenities are saved at ₱0.00.
                        </p>
                    @endif

                    <div class="flex justify-end gap-3 pt-2">
                        <flux:button
                            type="button"
                            wire:click="resetForm"
                            variant="ghost"
                        >
                            Clear
                        </flux:button>

                        <flux:button type="submit" variant="primary">
                            {{ $editingId !== null
                                ? 'Save Changes'
                                : 'Create Amenity' }}
                        </flux:button>
                    </div>
                </form>
            </flux:card>
        </aside>
    </div>
</div>

<?php

use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\FacilityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
    #[Url(as: 'sort', except: 'facility_number')]
    public string $sortField = 'facility_number';
    #[Url(as: 'direction', except: 'asc')]
    public string $sortDirection = 'asc';
    #[Url(as: 'per_page', except: 10)]
    public int $perPage = 10;

    #[Computed]
    public function facilities(): LengthAwarePaginator
    {
        $sort = in_array($this->sortField, ['facility_number', 'facility_name', 'facility_status'], true)
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
            ->orderBy($sort, $direction)
            ->orderBy('facility_id')
            ->paginate($perPage);
    }

    #[Computed]
    public function facilityTypes(): Collection
    {
        return FacilityType::query()->orderBy('facility_type')->get();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedTypeFilter(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->resetPage(); }

    public function sortBy(string $field): void
    {
        if (! in_array($field, ['facility_number', 'facility_name', 'facility_status'], true)) {
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
            <flux:button href="{{ route('admin.facilities.create') }}" wire:navigate variant="primary">Add facilities</flux:button>
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
                <flux:button wire:click="clearFilters" variant="ghost">Clear</flux:button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[62rem] text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                    <tr>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('facility_number')" class="font-semibold">Number {{ $this->sortIcon('facility_number') }}</button></th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('facility_name')" class="font-semibold">Name {{ $this->sortIcon('facility_name') }}</button></th>
                        <th class="px-5 py-3 font-semibold">Type</th>
                        <th class="px-5 py-3 font-semibold">Facility category</th>
                        <th class="px-5 py-3 font-semibold">Capacity guidance</th>
                        <th class="px-5 py-3"><button type="button" wire:click="sortBy('facility_status')" class="font-semibold">Status {{ $this->sortIcon('facility_status') }}</button></th>
                        <th class="px-5 py-3 text-right font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($this->facilities as $facility)
                        <tr wire:key="facility-{{ $facility->facility_id }}">
                            <td class="px-5 py-4 font-mono text-xs">{{ $facility->facility_number ?? 'Not assigned' }}</td>
                            <td class="px-5 py-4 font-medium">{{ $facility->facility_name }}</td>
                            <td class="px-5 py-4">{{ $facility->facilityType?->facility_type }}</td>
                            <td class="px-5 py-4">{{ $facility->facilityProduct?->display_name ?? $facility->facility_size }}</td>
                            <td class="px-5 py-4">{{ $facility->facilityProduct?->strict_maximum ? 'Maximum '.$facility->max_capacity : 'Recommended '.$facility->min_capacity.'–'.$facility->max_capacity }}</td>
                            <td class="px-5 py-4"><flux:badge color="{{ $this->statusColor($facility->facility_status) }}">{{ $facility->facility_status }}</flux:badge></td>
                            <td class="px-5 py-4 text-right"><flux:button href="{{ route('admin.facilities.edit', $facility) }}" wire:navigate size="sm" variant="ghost">Edit</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-12 text-center text-zinc-500">No facility matches the filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800">{{ $this->facilities->links() }}</div>
    </flux:card>
</div>

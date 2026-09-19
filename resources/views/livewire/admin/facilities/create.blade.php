<?php

use App\Models\FacilityProduct;
use App\Services\FacilityManagementService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Add Facilities - Olaer Spring Resort')] class extends Component
{
    public string $facilityProductId = '';
    public int $quantity = 1;
    public string $namePrefix = '';
    public string $status = 'Available';

    #[Computed]
    public function products(): Collection
    {
        return FacilityProduct::query()
            ->with(['facilityType', 'productRates'])
            ->where('is_active', true)
            ->orderBy('facility_type_id')
            ->orderBy('display_name')
            ->get();
    }

    public function save(FacilityManagementService $facilities): void
    {
        $validated = $this->validate([
            'facilityProductId' => ['required', 'integer', 'exists:tbl_facility_product,facility_product_id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'namePrefix' => ['nullable', 'string', 'max:70'],
            'status' => ['required', 'in:Available,Unavailable'],
        ]);

        try {
            $created = $facilities->createFromPreset(
                (int) $validated['facilityProductId'],
                (int) $validated['quantity'],
                $validated['namePrefix'] ?: null,
                $validated['status'],
            );
            session()->flash('success', $created->count().' '.($created->count() === 1 ? 'facility' : 'facilities').' created with automatic numbering.');
            $this->redirect(route('admin.facilities.index'), navigate: true);
        } catch (\Throwable $exception) {
            $this->addError('facilityProductId', $exception->getMessage());
        }
    }
};

?>

<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <p class="text-sm text-zinc-500">Facility Management</p>
        <h1 class="text-2xl font-bold tracking-tight">Add facilities from a preset</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">The preset supplies type, category, capacity policy, schedule, and rates. Every physical facility receives a unique number.</p>
    </div>
    <flux:card>
        <form wire:submit="save" class="space-y-5">
            <flux:select wire:model="facilityProductId" label="Facility preset">
                <option value="">Choose preset</option>
                @foreach ($this->products as $product)
                    <option value="{{ $product->facility_product_id }}">{{ $product->facilityType?->facility_type }} — {{ $product->display_name }}</option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="quantity" type="number" min="1" max="100" label="Quantity" />
                <flux:select wire:model="status" label="Initial status"><option>Available</option><option>Unavailable</option></flux:select>
            </div>
            <flux:input wire:model="namePrefix" label="Optional name prefix" placeholder="Example: Poolside Cottage" description="Leave blank to use the preset name." />
            <div class="flex justify-end gap-3">
                <flux:button href="{{ route('admin.facilities.index') }}" wire:navigate variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Create facilities</flux:button>
            </div>
        </form>
    </flux:card>
</div>

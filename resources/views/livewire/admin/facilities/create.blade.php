<?php

use App\Models\Facility;
use App\Services\FacilityManagementService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Clone Facility - Olaer Spring Resort')] class extends Component
{
    public string $sourceFacilityId = '';
    public string $facilityName = '';
    public string $status = 'Available';

    #[Computed]
    public function facilities(): Collection
    {
        return Facility::query()
            ->with(['facilityType', 'facilityProduct'])
            ->orderBy('facility_type_id')
            ->orderBy('facility_name')
            ->get();
    }

    public function updatedSourceFacilityId(
        FacilityManagementService $facilities,
    ): void {
        $source = Facility::query()
            ->with('facilityProduct')
            ->find((int) $this->sourceFacilityId);

        if ($source === null) {
            $this->facilityName = '';

            return;
        }

        $this->facilityName = $facilities->suggestedCloneName($source);
    }

    public function save(FacilityManagementService $facilities): void
    {
        $validated = $this->validate([
            'sourceFacilityId' => [
                'required',
                'integer',
                'exists:tbl_facility,facility_id',
            ],
            'facilityName' => [
                'required',
                'string',
                'max:100',
                'unique:tbl_facility,facility_name',
            ],
            'status' => ['required', 'in:Available,Unavailable'],
        ]);

        try {
            $clone = $facilities->cloneFacility(
                (int) $validated['sourceFacilityId'],
                $validated['facilityName'],
                $validated['status'],
            );

            session()->flash(
                'success',
                "Facility {$clone->facility_name} cloned successfully.",
            );
            $this->redirect(
                route('admin.facilities.index'),
                navigate: true,
            );
        } catch (\Throwable $exception) {
            $this->addError('sourceFacilityId', $exception->getMessage());
        }
    }
};

?>

<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <p class="text-sm text-zinc-500">Facility Management</p>
        <h1 class="text-2xl font-bold tracking-tight">Clone facility</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
            Copy a physical facility's product, capacity, rates, and inclusive amenities. The source facility name is never copied; review the suggested name before saving.
        </p>
    </div>

    <flux:card>
        <form wire:submit="save" class="space-y-5">
            <x-required-select wire:model.live="sourceFacilityId" name="sourceFacilityId" label="Source facility">
                <option value="">Choose facility to clone</option>
                @foreach ($this->facilities as $facility)
                    <option value="{{ $facility->facility_id }}">
                        {{ $facility->facility_name }}
                        ({{ $facility->facilityProduct?->display_name ?? $facility->facilityType?->facility_type }})
                    </option>
                @endforeach
            </x-required-select>

            <x-required-input
                wire:model="facilityName"
                name="facilityName"
                label="New facility name"
                placeholder="Example: C900-006"
            />

            <x-required-select wire:model="status" name="status" label="Initial status">
                <option>Available</option>
                <option>Unavailable</option>
            </x-required-select>

            <div class="flex justify-end gap-3">
                <flux:button href="{{ route('admin.facilities.index') }}" wire:navigate variant="ghost">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Clone facility
                </flux:button>
            </div>
        </form>
    </flux:card>
</div>

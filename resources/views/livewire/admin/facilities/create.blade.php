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
    public string $facilityNumber = '';
    public string $facilityName = '';
    public string $status = 'Available';

    #[Computed]
    public function facilities(): Collection
    {
        return Facility::query()
            ->with(['facilityType', 'facilityProduct'])
            ->orderBy('facility_type_id')
            ->orderBy('facility_number')
            ->get();
    }

    public function updatedSourceFacilityId(
        FacilityManagementService $facilities,
    ): void {
        $source = Facility::query()
            ->with('facilityProduct')
            ->find((int) $this->sourceFacilityId);

        if ($source === null) {
            $this->facilityNumber = '';
            $this->facilityName = '';

            return;
        }

        try {
            $this->facilityNumber = $facilities->suggestedCloneNumber($source);
        } catch (\Throwable) {
            $this->facilityNumber = '';
        }

        $this->facilityName = $source->facility_name.' Copy';
    }

    public function save(FacilityManagementService $facilities): void
    {
        $validated = $this->validate([
            'sourceFacilityId' => [
                'required',
                'integer',
                'exists:tbl_facility,facility_id',
            ],
            'facilityNumber' => [
                'required',
                'string',
                'max:50',
                'unique:tbl_facility,facility_number',
            ],
            'facilityName' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:Available,Unavailable'],
        ]);

        try {
            $clone = $facilities->cloneFacility(
                (int) $validated['sourceFacilityId'],
                $validated['facilityNumber'],
                $validated['facilityName'],
                $validated['status'],
            );

            session()->flash(
                'success',
                "Facility {$clone->facility_number} cloned successfully.",
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
            Copy a physical facility's product, capacity, rates, and inclusive amenities. The source facility number is never copied; review the new number and name before saving.
        </p>
    </div>

    <flux:card>
        <form wire:submit="save" class="space-y-5">
            <flux:select wire:model.live="sourceFacilityId" label="Source facility *">
                <option value="">Choose facility to clone</option>
                @foreach ($this->facilities as $facility)
                    <option value="{{ $facility->facility_id }}">
                        {{ $facility->facility_number }} — {{ $facility->facility_name }}
                        ({{ $facility->facilityProduct?->display_name ?? $facility->facilityType?->facility_type }})
                    </option>
                @endforeach
            </flux:select>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input
                    wire:model="facilityNumber"
                    label="New facility number *"
                    placeholder="Example: COT-SMA-056"
                />
                <flux:input
                    wire:model="facilityName"
                    label="New facility name *"
                    placeholder="Example: Poolside Cottage 56"
                />
            </div>

            <flux:select wire:model="status" label="Initial status *">
                <option>Available</option>
                <option>Unavailable</option>
            </flux:select>

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

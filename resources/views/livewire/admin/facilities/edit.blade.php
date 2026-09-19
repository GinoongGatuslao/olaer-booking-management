<?php

use App\Models\Facility;
use App\Services\FacilityManagementService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Edit Facility - Olaer Spring Resort')] class extends Component
{
    #[Locked]
    public int $facilityId;
    public string $facilityNumber = '';
    public string $facilityName = '';
    public string $status = 'Available';
    public string $productName = '';
    public string $typeName = '';

    public function mount(Facility $facility): void
    {
        $facility->load(['facilityType', 'facilityProduct']);
        $this->facilityId = (int) $facility->facility_id;
        $this->facilityNumber = (string) $facility->facility_number;
        $this->facilityName = (string) $facility->facility_name;
        $this->status = (string) $facility->facility_status;
        $this->productName = (string) ($facility->facilityProduct?->display_name ?? $facility->facility_size);
        $this->typeName = (string) $facility->facilityType?->facility_type;
    }

    public function save(FacilityManagementService $facilities): void
    {
        $validated = $this->validate([
            'facilityNumber' => ['required', 'string', 'max:50'],
            'facilityName' => ['required', 'string', 'max:50'],
            'status' => ['required', 'in:Available,Unavailable,Booked,Occupied'],
        ]);

        try {
            $facilities->updateIdentity(
                Facility::query()->findOrFail($this->facilityId),
                $validated['facilityNumber'],
                $validated['facilityName'],
                $validated['status'],
            );
            session()->flash('success', 'Facility updated.');
            $this->redirect(route('admin.facilities.index'), navigate: true);
        } catch (\Throwable $exception) {
            $this->addError('facilityNumber', $exception->getMessage());
        }
    }
};

?>

<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <p class="text-sm text-zinc-500">{{ $typeName }} — {{ $productName }}</p>
        <h1 class="text-2xl font-bold tracking-tight">Edit physical facility</h1>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">The facility preset stays fixed so pricing and scheduling remain coherent.</p>
    </div>
    <flux:card>
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="facilityNumber" label="Facility number" />
                <flux:input wire:model="facilityName" label="Facility name" />
            </div>
            <flux:select wire:model="status" label="Operational status" :disabled="in_array($status, ['Booked', 'Occupied'], true)">
                @if (in_array($status, ['Booked', 'Occupied'], true))
                    <option value="{{ $status }}">{{ $status }}</option>
                @else
                    <option>Available</option><option>Unavailable</option>
                @endif
            </flux:select>
            <div class="flex justify-end gap-3">
                <flux:button href="{{ route('admin.facilities.index') }}" wire:navigate variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save changes</flux:button>
            </div>
        </form>
    </flux:card>
</div>

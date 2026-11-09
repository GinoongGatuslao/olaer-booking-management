<?php

use App\Models\EntranceFee;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Entrance Fee Management - Olaer Spring Resort')] class extends Component
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

    public ?int $editingId = null;
    public string $entranceFeeName = '';
    public string $entranceFeeRate = '';
    public bool $showEditor = false;

    #[Computed]
    public function entranceFees(): LengthAwarePaginator
    {
        return EntranceFee::query()
            ->orderBy('entrance_fee_name')
            ->paginate(10);
    }

    public function edit(int $entranceFeeId): void
    {
        $fee = EntranceFee::query()->findOrFail($entranceFeeId);
        $this->editingId = (int) $fee->entrance_fee_id;
        $this->entranceFeeName = (string) $fee->entrance_fee_name;
        $this->entranceFeeRate = number_format((float) $fee->entrance_fee_price, 2, '.', '');
        $this->showEditor = true;
        $this->resetValidation();
    }

    public function closeEditor(): void
    {
        $this->reset(['editingId', 'entranceFeeName', 'entranceFeeRate', 'showEditor']);
        $this->resetValidation();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'editingId' => ['required', 'integer', 'exists:tbl_entrance_fee,entrance_fee_id'],
            'entranceFeeRate' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
        ], [
            'entranceFeeRate.min' => 'Entrance fee rate must be greater than ₱0.00.',
        ]);

        EntranceFee::query()->findOrFail((int) $validated['editingId'])->update([
            'entrance_fee_price' => number_format((float) $validated['entranceFeeRate'], 2, '.', ''),
        ]);

        session()->flash('success', 'Entrance fee rate updated. Future entrance slips will use the new rate.');
        $this->closeEditor();
        unset($this->entranceFees);
    }
};

?>

<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Entrance Fee Management</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Manage entrance fee categories and rates.</p>
        </div>
        <flux:button href="{{ route('admin.dashboard') }}" wire:navigate variant="ghost">Back to Dashboard</flux:button>
    </div>

    <x-toast :message="session('success')" fresh />


    <flux:card class="overflow-hidden p-0">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-zinc-200 bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900/50">
                <tr>
                    <th class="px-5 py-3 font-semibold">Entrance fee category</th>
                    <th class="px-5 py-3 font-semibold">Rate</th>
                    <th class="px-5 py-3 text-right font-semibold">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->entranceFees as $fee)
                    <tr wire:key="entrance-fee-{{ $fee->entrance_fee_id ? 'bg-blue-50 ring-1 ring-inset ring-blue-200 dark:bg-blue-950/30 dark:ring-blue-800' : ''}}" class="hover:bg-brand-surface-muted/70 dark:hover:bg-zinc-800/50">
                        <td class="px-5 py-4 font-medium">{{ $fee->entrance_fee_name }}</td>
                        <td class="px-5 py-4 font-semibold">₱{{ number_format((float) $fee->entrance_fee_price, 2) }}</td>
                        <td class="px-5 py-4 text-right"><flux:button type="button" wire:click="edit({{ $fee->entrance_fee_id }})" icon="pencil-square" variant="ghost" size="sm">Edit rate</flux:button></td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-5 py-12 text-center text-zinc-500">No entrance fee categories configured.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800">{{ $this->entranceFees->links() }}</div>
    </flux:card>

    <flux:modal wire:model="showEditor" class="md:w-[30rem]">
        <form wire:submit="save" novalidate class="space-y-5">
            <div>
                <flux:heading size="lg">Edit entrance fee rate</flux:heading>
                <flux:text class="mt-1">{{ $entranceFeeName }}</flux:text>
            </div>
            <flux:field class="relative">
                <flux:label>Entrance fee rate <span class="text-red-500">*</span></flux:label>
                <flux:input wire:model="entranceFeeRate" type="number" min="0.01" step="0.01" prefix="₱" :invalid="$errors->has('entranceFeeRate')" />
                @if ($msg = $this->visibleError('entranceFeeRate'))
                    <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $msg }}</p>
                @endif
            </flux:field>
            <div class="flex justify-end gap-3">
                <flux:button type="button" wire:click="closeEditor" variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save rate</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

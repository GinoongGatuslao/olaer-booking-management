@props([
    'options' => [],
    'error' => null,
    'label' => 'Barangay',
    'enabled' => true,
    'listed' => true,
])

{{-- Barangay dropdown for the chosen city/municipality (optional field). Text box when there is no list. --}}
<flux:field class="relative">
    <flux:label>{{ $label }}</flux:label>

    @if ($listed && (count($options) > 0 || $enabled === false))
        <flux:select wire:model="barangay" :invalid="$errors->has('barangay')" :disabled="! $enabled">
            <option value="">{{ $enabled ? 'Select barangay' : 'Select a city first' }}</option>
            @foreach ($options as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </flux:select>
    @else
        <flux:input wire:model="barangay" :invalid="$errors->has('barangay')" />
    @endif

    @if ($error)
        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $error }}</p>
    @endif
</flux:field>

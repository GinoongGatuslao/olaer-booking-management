@props([
    'options' => [],
    'error' => null,
    'label' => 'City / Municipality',
    'enabled' => true,
    'listed' => true,
])

{{-- City / municipality dropdown for the chosen province. Text box when there is no list. --}}
<flux:field class="relative">
    <flux:label>{{ $label }} <span class="text-red-500">*</span></flux:label>

    @if ($listed && (count($options) > 0 || $enabled === false))
        <flux:select wire:model.live="city" :invalid="$errors->has('city')" :disabled="! $enabled">
            <option value="">{{ $enabled ? 'Select city / municipality' : 'Select a province first' }}</option>
            @foreach ($options as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </flux:select>
    @else
        <flux:input wire:model="city" :invalid="$errors->has('city')" />
    @endif

    @if ($error)
        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $error }}</p>
    @endif
</flux:field>

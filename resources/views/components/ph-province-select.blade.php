@props([
    'options' => [],
    'error' => null,
    'label' => 'Province',
])

{{-- Province dropdown. Falls back to a text box until `php artisan locations:import` has been run. --}}
<flux:field class="relative">
    <flux:label>{{ $label }} <span class="text-red-500">*</span></flux:label>

    @if (count($options) > 0)
        <flux:select wire:model.live="province" :invalid="$errors->has('province')">
            <option value="">Select province</option>
            @foreach ($options as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </flux:select>
    @else
        <flux:input wire:model="province" :invalid="$errors->has('province')" />
    @endif

    @if ($error)
        <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $error }}</p>
    @endif
</flux:field>

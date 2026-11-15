@props([
    'options' => [],
])

{{-- Region dropdown. It only narrows the province list and is not saved. Hidden until
     `php artisan locations:import` has loaded the lists. --}}
@if (count($options) > 0)
    <flux:field class="relative">
        <flux:label>Region</flux:label>
        <flux:select wire:model.live="region">
            <option value="">Select region</option>
            @foreach ($options as $option)
                <option value="{{ $option }}">{{ $option }}</option>
            @endforeach
        </flux:select>
    </flux:field>
@endif

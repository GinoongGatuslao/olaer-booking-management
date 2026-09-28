@props(['label', 'name'])

<flux:field>
    <flux:label>
        {{ $label }} <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>
    </flux:label>
    <flux:select {{ $attributes }} required>
        {{ $slot }}
    </flux:select>
    <flux:error :name="$name" />
</flux:field>

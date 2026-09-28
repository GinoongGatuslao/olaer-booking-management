@props(['label', 'name'])

<flux:field>
    <flux:label>
        {{ $label }} <span class="text-red-600 dark:text-red-400" aria-hidden="true">*</span>
    </flux:label>
    <flux:textarea {{ $attributes }} required />
    <flux:error :name="$name" />
</flux:field>

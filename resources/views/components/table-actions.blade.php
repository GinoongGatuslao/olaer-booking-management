@props(['label' => 'Row actions'])

<flux:dropdown position="bottom" align="end">
    <flux:button type="button" size="sm" variant="ghost" icon="ellipsis-vertical" aria-label="{{ $label }}" title="{{ $label }}" />
    <flux:menu>
        {{ $slot }}
    </flux:menu>
</flux:dropdown>

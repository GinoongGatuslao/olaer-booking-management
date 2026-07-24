@props([
    'sidebar' => false,
])

@if ($sidebar)
    <flux:sidebar.brand name="Olaer Spring Resort" {{ $attributes }}>
        <x-slot
            name="logo"
            class="flex aspect-square size-9 items-center justify-center rounded-xl bg-brand-primary text-white shadow-sm shadow-brand-primary/20"
        >
            <x-app-logo-icon class="size-6" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="Olaer Spring Resort" {{ $attributes }}>
        <x-slot
            name="logo"
            class="flex aspect-square size-9 items-center justify-center rounded-xl bg-brand-primary text-white shadow-sm shadow-brand-primary/20"
        >
            <x-app-logo-icon class="size-6" />
        </x-slot>
    </flux:brand>
@endif

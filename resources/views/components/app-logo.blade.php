@props(['sidebar' => false])

@if ($sidebar)
    <flux:sidebar.brand name="Olaer Spring Resort" {{ $attributes }}>
        <x-slot name="logo" class="flex size-8 items-center justify-center">
            <img src="/images/olaer/logo.png"
                 alt="Olaer Spring Resort Logo"
                 class="size-8 rounded-full object-cover" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="Olaer Spring Resort" {{ $attributes }}>
        <x-slot name="logo" class="flex size-8 items-center justify-center">
            <img src="/images/olaer/logo.png"
                 alt="Olaer Spring Resort Logo"
                 class="size-8 rounded-full object-cover" />
        </x-slot>
    </flux:brand>
@endif
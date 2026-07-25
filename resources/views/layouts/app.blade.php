<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'Olaer Spring Resort') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>

    <body class="min-h-screen bg-brand-surface text-brand-text antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <a
            href="#main-content"
            class="fixed start-4 top-4 z-50 -translate-y-24 rounded-lg bg-brand-primary px-4 py-2 text-sm font-semibold text-white shadow-lg transition focus:translate-y-0"
        >
            Skip to main content
        </a>

        <flux:sidebar
            sticky
            collapsible
            class="border-e border-brand-border bg-white dark:border-zinc-700 dark:bg-zinc-900"
        >
            <flux:sidebar.header class="border-b border-brand-border dark:border-white/10">
                <x-app-logo
                    :sidebar="true"
                    :href="route($staffDashboardRoute)"
                    wire:navigate
                />
                <flux:sidebar.collapse
                    tooltip="Collapse navigation"
                    class="text-brand-text-muted hover:text-brand-primary dark:text-white/75 dark:hover:text-white"
                />
            </flux:sidebar.header>

            <div class="px-3 pb-2 pt-4 in-data-flux-sidebar-collapsed-desktop:hidden">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-primary">
                    {{ $staffRoleName }} workspace
                </p>
                <p class="mt-1 text-xs text-brand-text-muted dark:text-white/70">
                    Booking and billing operations
                </p>
            </div>

            <livewire:shared.staff-navigation />

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item
                    icon="globe-alt"
                    :href="route('guest.home')"
                    tooltip="Open public website"
                    target="_blank"
                >
                    Public Website
                </flux:sidebar.item>
                <flux:sidebar.item
                    icon="cog-6-tooth"
                    :href="route('profile.edit')"
                    :current="request()->routeIs('profile.*', 'appearance.*', 'security.*')"
                    wire:navigate
                >
                    Account Settings
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" />
        </flux:sidebar>

        <flux:header class="border-b border-brand-border bg-white/95 shadow-sm backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">
            <flux:sidebar.toggle
                class="lg:hidden"
                icon="bars-2"
                inset="left"
                aria-label="Open navigation"
            />

            <div class="min-w-0">
                <x-staff-breadcrumbs class="max-sm:hidden" />
                <p class="truncate text-sm font-semibold text-brand-text sm:hidden dark:text-zinc-100">
                    {{ $staffRoleName }} workspace
                </p>
            </div>

            <flux:spacer />

            @if ($staffQuickAction && Route::has($staffQuickAction['route']))
                <flux:button
                    :href="route($staffQuickAction['route'])"
                    :icon="$staffQuickAction['icon']"
                    variant="primary"
                    size="sm"
                    class="max-sm:hidden"
                    wire:navigate
                >
                    {{ $staffQuickAction['label'] }}
                </flux:button>
            @endif

            <flux:dropdown position="bottom" align="end" class="lg:hidden">
                <flux:profile :name="$staffDisplayName" />

                <flux:menu>
                    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                        <flux:avatar :name="$staffDisplayName" />
                        <div class="grid min-w-0 flex-1 text-start text-sm leading-tight">
                            <flux:heading class="truncate">{{ $staffDisplayName }}</flux:heading>
                            <flux:text class="truncate">{{ $staffUser?->email }}</flux:text>
                        </div>
                    </div>

                    <flux:menu.separator />

                    <flux:menu.item
                        :href="route('profile.edit')"
                        icon="cog-6-tooth"
                        wire:navigate
                    >
                        Account Settings
                    </flux:menu.item>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                        >
                            Log out
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        <flux:main
            id="main-content"
            tabindex="-1"
            class="bg-brand-surface dark:bg-zinc-950"
        >
            <div class="mx-auto w-full max-w-[100rem]">
                {{ $slot }}
            </div>
        </flux:main>

        @persist('staff-toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @livewireScripts
        @fluxScripts
    </body>
</html>

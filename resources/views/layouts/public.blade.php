<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta
            name="description"
            content="{{ $description ?? 'Reserve facilities and plan your visit to Olaer Spring Resort in General Santos City.' }}"
        >

        <title>{{ $title ?? 'Olaer Spring Resort' }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @fluxAppearance
    </head>

    <body class="min-h-screen bg-public-cream text-public-ink antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <a
            href="#main-content"
            class="fixed start-4 top-4 z-50 -translate-y-24 rounded-full bg-public-forest px-4 py-2 text-sm font-semibold text-white shadow-lg transition focus:translate-y-0"
        >
            Skip to main content
        </a>

        <x-public-navigation />

        <main id="main-content" tabindex="-1">
            {{ $slot }}
        </main>

        <x-public-footer />

        @livewireScripts
        @fluxScripts
    </body>
</html>

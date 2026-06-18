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

<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <div class="min-h-screen">
        <header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                <div>
                    <div class="text-base font-semibold">Olaer Spring Resort</div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ auth()->user()?->role?->role_name ?? 'User' }} Panel
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <span class="hidden text-sm text-zinc-600 dark:text-zinc-300 sm:inline">
                        {{ auth()->user()?->full_name }}
                    </span>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <flux:button type="submit" variant="subtle" size="sm">Logout</flux:button>
                    </form>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
    @fluxScripts
</body>

</html>

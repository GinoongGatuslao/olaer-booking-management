<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Olaer Spring Resort' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased">
    <header class="border-b bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
            <a href="{{ route('guest.home') }}" class="text-lg font-semibold">Olaer Spring Resort</a>
            <nav class="flex gap-4 text-sm">
                <a href="{{ route('guest.home') }}" class="hover:underline">Home</a>
                <a href="{{ route('guest.reservations.create') }}" class="hover:underline">Reserve</a>
                <a href="{{ route('guest.bookings.create') }}" class="hover:underline">Book</a>
                <a href="{{ route('guest.reservations.manage') }}" class="hover:underline">Manage Reservation</a>
                <a href="{{ route('guest.confirmations.lookup') }}" class="text-zinc-600 hover:text-zinc-950 dark:text-zinc-300 dark:hover:text-white">Confirmation</a>
                <a href="{{ route('login') }}" class="hover:underline">Staff Login</a>
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>

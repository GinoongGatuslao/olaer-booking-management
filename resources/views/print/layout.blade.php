<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Olaer Spring Resort Document' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @page { margin: 12mm; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .print-card { box-shadow: none !important; border: 1px solid #111827 !important; }
        }
    </style>
</head>
<body class="bg-zinc-100 text-zinc-950">
    <main class="mx-auto max-w-4xl p-4 print:p-0">
        <div class="no-print mb-4 flex items-center justify-between gap-3 rounded-xl bg-white p-4 shadow-sm">
            <div>
                <p class="text-sm text-zinc-500">Print Preview</p>
                <p class="font-semibold">{{ $title ?? 'Olaer Spring Resort Document' }}</p>
            </div>
            <div class="flex gap-2">
                <button onclick="window.history.back()" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-50">Back</button>
                <button onclick="window.print()" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800">Print</button>
            </div>
        </div>

        <section class="print-card rounded-2xl bg-white p-8 shadow-sm print:rounded-none">
            <header class="mb-6 border-b border-zinc-200 pb-4 text-center">
                <h1 class="text-2xl font-bold tracking-tight">Olaer Spring Resort</h1>
                <p class="text-sm text-zinc-600">Booking Management with Billing System</p>
                <p class="mt-1 text-xs text-zinc-500">Generated: {{ now()->format('M d, Y h:i A') }}</p>
            </header>

            {{ $slot }}
        </section>
    </main>
</body>
</html>

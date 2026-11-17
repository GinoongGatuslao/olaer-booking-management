@props([
    'title' => 'Olaer Spring Resort Document',
    // portrait (A4, default) | landscape (A4 landscape) | thermal (80 mm roll receipt)
    'size' => 'portrait',
])

@php
    $thermal = $size === 'thermal';
    $landscape = $size === 'landscape';
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @if ($thermal)
            /* 80 mm roll: the page is as wide as the paper and as long as the content. */
            @page { size: 80mm auto; margin: 0; }
        @elseif ($landscape)
            @page { size: A4 landscape; margin: 10mm; }
        @else
            @page { margin: 12mm; }
        @endif

        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .print-card { box-shadow: none !important; }
            @if ($thermal)
                html, body { width: 80mm; margin: 0; padding: 0; }
                .print-card { border: 0 !important; padding: 3mm 4mm !important; }
            @else
                .print-card { border: 1px solid #111827 !important; }
                body { padding: {{ $landscape ? '0' : '12mm' }}; }
            @endif
        }

        @if ($thermal)
            .thermal { width: 80mm; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11px; line-height: 1.35; color: #000; }
            .thermal * { box-sizing: border-box; }
            .t-center { text-align: center; }
            .t-row { display: flex; justify-content: space-between; align-items: baseline; gap: 6px; }
            .t-row > :first-child { min-width: 0; overflow-wrap: anywhere; }
            .t-row > :last-child { text-align: right; white-space: nowrap; }
            .t-sep { border-top: 1px dashed #000; margin: 6px 0; }
            .t-strong { font-weight: 700; }
            .t-small { font-size: 10px; }
            .t-big { font-size: 13px; font-weight: 700; }
            .t-sign { margin-top: 22px; border-top: 1px solid #000; padding-top: 2px; text-align: center; font-size: 10px; }
        @endif
    </style>
</head>
<body class="bg-zinc-100 text-zinc-950">
    <main
        @if ($thermal)
            class="mx-auto p-4 print:p-0" style="width: 80mm;"
        @elseif ($landscape)
            class="mx-auto max-w-[297mm] p-4 print:max-w-none print:p-0"
        @else
            class="mx-auto max-w-4xl p-4 print:p-0"
        @endif
    >
        <div class="no-print mb-4 flex items-center justify-between gap-3 rounded-xl bg-white p-4 shadow-sm" @if ($thermal) style="width: 80mm;" @endif>
            <div>
                <p class="text-sm text-zinc-500">Print Preview{{ $thermal ? ' · 80 mm thermal' : ($landscape ? ' · Landscape' : '') }}</p>
                <p class="font-semibold">{{ $title }}</p>
            </div>
            <div class="flex gap-2">
                <button onclick="if (window.opener && !window.opener.closed) { window.close(); } else if (window.history.length > 1) { window.history.back(); } else { window.location.href = '/'; }" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-50">Back</button>
                <button onclick="window.print()" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800">Print</button>
            </div>
        </div>

        @if ($thermal)
            <section class="print-card thermal bg-white shadow-sm" style="padding: 3mm 4mm;">
                <header class="t-center">
                    <p class="t-big">Olaer Spring Resort</p>
                    <p class="t-small">Purok Olaer, General Santos City (Dadiangas), 9500 South Cotabato</p>
                    <p class="t-small">{{ now()->format('M d, Y h:i A') }}</p>
                </header>
                <div class="t-sep"></div>

                {{ $slot }}
            </section>
        @else
            <section class="print-card rounded-2xl bg-white p-8 shadow-sm print:rounded-none">
                <header class="mb-6 border-b border-zinc-200 pb-4 text-center">
                    <h1 class="text-2xl font-bold tracking-tight">Olaer Spring Resort</h1>
                    <p class="text-sm text-zinc-600">Booking Management with Billing System</p>
                    <p class="mt-1 text-xs text-zinc-500">Generated: {{ now()->format('M d, Y h:i A') }}</p>
                </header>

                {{ $slot }}
            </section>
        @endif
    </main>
</body>
</html>

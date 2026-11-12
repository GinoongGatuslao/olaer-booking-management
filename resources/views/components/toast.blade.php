@props([
    'message' => null,
    'type' => 'success',
    'seconds' => 6,
    // Flash messages ask for a fresh element so a repeated message shows again.
    'fresh' => false,
])

@php
    $styles = [
        'success' => 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200',
        'error' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200',
        'info' => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200',
    ];

    $key = 'toast-'.$type.'-'.md5((string) $message).($fresh ? '-'.microtime(true) : '');
@endphp

@if (filled($message))
    @php
        // Tell the layout this page already shows the flash message, so the
        // layout's fallback toast doesn't show it a second time.
        request()->attributes->set('toast_rendered', true);
    @endphp

    {{-- Floats over the page at the top right, so it never pushes the layout around. --}}
    <div
        wire:key="{{ $key }}"
        x-data="{ show: true }"
        x-init="setTimeout(() => show = false, {{ (int) $seconds * 1000 }})"
        x-show="show"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-x-4 opacity-0"
        x-transition:enter-end="translate-x-0 opacity-100"
        x-transition:leave="transition ease-in duration-300"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="pointer-events-none fixed right-4 top-4 z-[9999] w-[calc(100%-2rem)] max-w-sm"
        role="status"
        aria-live="polite"
    >
        <div class="pointer-events-auto flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg {{ $styles[$type] ?? $styles['success'] }}">
            <span class="flex-1">{{ $message }}</span>

            {{ $slot }}

            <button
                type="button"
                x-on:click="show = false"
                class="shrink-0 leading-none opacity-60 hover:opacity-100"
                aria-label="Dismiss"
            >&times;</button>
        </div>
    </div>
@endif

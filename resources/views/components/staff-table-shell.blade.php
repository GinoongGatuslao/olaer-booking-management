@props([
    'firstItem' => 0,
    'lastItem' => 0,
    'total' => 0,
    'recordLabel' => 'records',
    'loadingTarget' => null,
])

<section
    {{ $attributes->class([
        'overflow-hidden rounded-2xl border border-brand-border bg-brand-surface shadow-sm dark:border-zinc-800 dark:bg-zinc-900',
    ]) }}
>
    @if (isset($filters))
        {{ $filters }}
    @endif

    <div class="relative">
        @if ($loadingTarget)
            <div
                wire:loading.delay
                wire:target="{{ $loadingTarget }}"
                class="pointer-events-none absolute inset-x-0 top-3 z-10 text-center"
            >
                <span class="inline-flex rounded-full border border-brand-border bg-white/95 px-3 py-1 text-xs font-medium text-brand-text-muted shadow-sm dark:border-zinc-700 dark:bg-zinc-950/95 dark:text-zinc-300">
                    Updating list…
                </span>
            </div>
        @endif

        <div
            class="overflow-x-auto transition-opacity duration-150"
            @if ($loadingTarget)
                wire:loading.class="opacity-50"
                wire:target="{{ $loadingTarget }}"
            @endif
        >
            {{ $slot }}
        </div>
    </div>

    <footer class="flex flex-col gap-3 border-t border-brand-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
        <p class="text-sm text-brand-text-muted dark:text-zinc-400">
            Showing
            {{ $firstItem ?? 0 }}
            to
            {{ $lastItem ?? 0 }}
            of
            {{ $total }}
            {{ $recordLabel }}
        </p>

        @if (isset($pagination))
            {{ $pagination }}
        @endif
    </footer>
</section>

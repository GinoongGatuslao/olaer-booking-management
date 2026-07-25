@props([
    'eyebrow',
    'title',
    'description',
])

<header {{ $attributes->class(['flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between']) }}>
    <div class="min-w-0">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-primary dark:text-emerald-300">
            {{ $eyebrow }}
        </p>

        <h1 class="mt-1 text-2xl font-semibold tracking-tight text-brand-text sm:text-3xl dark:text-white">
            {{ $title }}
        </h1>

        <p class="mt-2 max-w-3xl text-sm leading-6 text-brand-text-muted dark:text-zinc-300">
            {{ $description }}
        </p>
    </div>

    @if (isset($actions))
        <div class="flex shrink-0 flex-wrap gap-2">
            {{ $actions }}
        </div>
    @endif
</header>

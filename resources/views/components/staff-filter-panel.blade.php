@props([
    'title',
    'description',
    'count' => null,
    'countLabel' => 'records',
])

<section
    {{ $attributes->class([
        'border-b border-brand-border bg-gradient-to-br from-white via-white to-brand-surface-muted/70 p-5 dark:border-zinc-800 dark:from-zinc-900 dark:via-zinc-900 dark:to-zinc-950',
    ]) }}
>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="font-semibold text-brand-text dark:text-white">
                {{ $title }}
            </h2>

            <p class="mt-1 max-w-3xl text-sm leading-6 text-brand-text-muted dark:text-zinc-400">
                {{ $description }}
            </p>
        </div>

        @if ($count !== null)
            <flux:badge color="zinc" size="sm" class="shrink-0">
                {{ $count }} {{ $countLabel }}
            </flux:badge>
        @endif
    </div>

    <div class="mt-5">
        {{ $slot }}
    </div>

    @if (isset($actions))
        <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
            {{ $actions }}
        </div>
    @endif
</section>

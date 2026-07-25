@props([
    'title',
    'description',
    'href' => null,
    'action' => null,
])

<div {{ $attributes->class(['rounded-2xl border border-dashed border-brand-border bg-brand-surface/70 px-5 py-8 text-center dark:border-zinc-700 dark:bg-zinc-950/40']) }}>
    <p class="font-semibold text-brand-text dark:text-white">
        {{ $title }}
    </p>

    <p class="mx-auto mt-1 max-w-xl text-sm leading-6 text-brand-text-muted dark:text-zinc-400">
        {{ $description }}
    </p>

    @if ($href && $action)
        <flux:button
            :href="$href"
            wire:navigate
            size="sm"
            variant="ghost"
            class="mt-4"
        >
            {{ $action }}
        </flux:button>
    @endif
</div>

@props([
    'eyebrow',
    'title',
    'href',
    'description' => null,
    'tone' => 'primary',
])

@php
    $accentClass = match ($tone) {
        'success' => 'bg-brand-success',
        'warning' => 'bg-brand-warning',
        'danger' => 'bg-brand-danger',
        'info' => 'bg-brand-info',
        'secondary' => 'bg-brand-secondary',
        default => 'bg-brand-primary',
    };
@endphp

<a
    href="{{ $href }}"
    wire:navigate
    {{ $attributes->class([
        'group relative overflow-hidden rounded-2xl border border-brand-border bg-white p-5 shadow-sm transition',
        'hover:-translate-y-0.5 hover:border-brand-secondary hover:shadow-md',
        'dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-emerald-600',
    ]) }}
>
    <span class="absolute inset-y-0 start-0 w-1 {{ $accentClass }}"></span>

    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-text-muted dark:text-zinc-400">
        {{ $eyebrow }}
    </p>

    <p class="mt-2 font-semibold text-brand-text group-hover:text-brand-primary dark:text-white dark:group-hover:text-emerald-300">
        {{ $title }}
    </p>

    @if ($description)
        <p class="mt-1 text-sm leading-6 text-brand-text-muted dark:text-zinc-400">
            {{ $description }}
        </p>
    @endif
</a>

@props([
    'label',
    'value',
    'description' => null,
    'href' => null,
    'action' => null,
    'tone' => 'primary',
])

@php
    $toneClasses = match ($tone) {
        'success' => 'bg-brand-success',
        'warning' => 'bg-brand-warning',
        'danger' => 'bg-brand-danger',
        'info' => 'bg-brand-info',
        'secondary' => 'bg-brand-secondary',
        default => 'bg-brand-primary',
    };
@endphp

<article
    {{ $attributes->class([
        'relative overflow-hidden rounded-2xl border border-brand-border bg-white p-5 shadow-sm',
        'dark:border-zinc-700 dark:bg-zinc-900',
    ]) }}
>
    <span class="absolute inset-x-0 top-0 h-1 {{ $toneClasses }}"></span>

    <p class="text-sm font-medium text-brand-text-muted dark:text-zinc-300">
        {{ $label }}
    </p>

    <p class="mt-2 text-3xl font-semibold tracking-tight text-brand-text dark:text-white">
        {{ $value }}
    </p>

    @if ($description)
        <p class="mt-2 text-xs leading-5 text-brand-text-muted dark:text-zinc-400">
            {{ $description }}
        </p>
    @endif

    @if ($href && $action)
        <a
            href="{{ $href }}"
            wire:navigate
            class="mt-3 inline-flex text-sm font-semibold text-brand-primary underline-offset-4 hover:underline dark:text-emerald-300"
        >
            {{ $action }}
        </a>
    @endif
</article>

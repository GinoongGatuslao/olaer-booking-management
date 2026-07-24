@props([
    'alert',
])

@php
    $severity = $alert['severity'] ?? 'info';

    [$badgeColor, $accentClass, $surfaceClass] = match ($severity) {
        'danger' => [
            'red',
            'bg-brand-danger',
            'bg-red-50/70 dark:bg-red-950/15',
        ],
        'warning' => [
            'amber',
            'bg-brand-warning',
            'bg-amber-50/70 dark:bg-amber-950/15',
        ],
        'success' => [
            'green',
            'bg-brand-success',
            'bg-emerald-50/70 dark:bg-emerald-950/15',
        ],
        default => [
            'sky',
            'bg-brand-info',
            'bg-sky-50/70 dark:bg-sky-950/15',
        ],
    };

    $severityLabel = $severity === 'danger'
        ? 'Critical'
        : ucfirst($severity);

    $routeName = $alert['route_name'] ?? null;
    $url = $routeName
        && \Illuminate\Support\Facades\Route::has($routeName)
            ? route(
                $routeName,
                $alert['route_params'] ?? [],
            )
            : null;
@endphp

<article
    {{ $attributes->class([
        'relative overflow-hidden rounded-2xl border border-brand-border p-5',
        'dark:border-zinc-700',
        $surfaceClass,
    ]) }}
>
    <span class="absolute inset-y-0 start-0 w-1 {{ $accentClass }}"></span>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <flux:badge :color="$badgeColor" size="sm">
                    {{ $severityLabel }}
                </flux:badge>

                @if (filled($alert['time_label'] ?? null))
                    <span class="text-xs text-brand-text-muted dark:text-zinc-400">
                        {{ $alert['time_label'] }}
                    </span>
                @endif
            </div>

            <h3 class="mt-3 font-semibold text-brand-text dark:text-white">
                {{ $alert['title'] ?? 'Operational alert' }}
            </h3>

            <p class="mt-1 text-sm leading-6 text-brand-text-muted dark:text-zinc-300">
                {{ $alert['message'] ?? '' }}
            </p>
        </div>

        @if ($url)
            <flux:button
                :href="$url"
                wire:navigate
                size="sm"
                variant="ghost"
                class="shrink-0"
            >
                {{ $alert['action_label'] ?? 'Open item' }}
            </flux:button>
        @endif
    </div>
</article>

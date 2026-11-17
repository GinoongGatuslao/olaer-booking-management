@props([
    // One message or a list of messages. Empty / null renders nothing.
    'messages' => null,
    // Optional bold line above a list, e.g. "Please fix the following:".
    'heading' => null,
    // Flash messages ask for a fresh element so a repeated message shows again.
    'fresh' => false,
    // Bump on every action that can raise an error, so identical errors show again.
    'tick' => 0,
])

@php
    $list = collect(is_array($messages) ? $messages : [$messages])
        ->flatten()
        ->map(fn ($message): string => trim((string) $message))
        ->filter()
        ->unique()
        ->values()
        ->all();

    $key = 'error-toast-'.$tick.'-'.md5(json_encode($list)).($fresh ? '-'.microtime(true) : '');

    // A flash set by an earlier action is still readable on the very next request. On a Livewire update that
    // would pop the same toast a second time, so only show a flash that was set during this request.
    $stale = $fresh
        && request()->hasHeader('X-Livewire')
        && collect(array_diff(session()->get('_flash.old', []), session()->get('_flash.new', [])))
            ->contains(fn ($flashKey): bool => session()->get($flashKey) === $messages);

    // Sit under a success/welcome toast that is already on screen instead of covering it.
    $top = request()->attributes->get('toast_rendered', false) ? 'top-24' : 'top-4';
@endphp

@if ($list !== [] && ! $stale)
    @php
        // Tell the layout this page already shows the error, so its fallback doesn't show it twice.
        request()->attributes->set('error_toast_rendered', true);
    @endphp

    {{-- Same toast everywhere: top right, lists every message, stays until it is fixed or dismissed. --}}
    <div
        wire:key="{{ $key }}"
        x-data="{ show: true }"
        x-show="show"
        x-transition.opacity.duration.200ms
        role="alert"
        class="fixed right-4 {{ $top }} z-[9999] max-h-[80vh] w-[calc(100vw-2rem)] max-w-sm overflow-y-auto rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 shadow-lg dark:border-red-900 dark:bg-red-950 dark:text-red-100"
    >
        <div class="flex items-start gap-3">
            <div class="min-w-0 flex-1">
                @if ($heading)
                    <p class="font-semibold">{{ $heading }}</p>
                @endif

                @if (count($list) === 1 && ! $heading)
                    <p>{{ $list[0] }}</p>
                @else
                    <ul @class(['list-disc space-y-1 pl-5', 'mt-1' => $heading])>
                        @foreach ($list as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <button type="button" x-on:click="show = false" class="shrink-0 leading-none text-red-700 hover:text-red-900 dark:text-red-200 dark:hover:text-white" aria-label="Dismiss">&times;</button>
        </div>
    </div>
@endif

@props([
    'compact' => false,
])

<a
    href="{{ route('guest.home') }}"
    {{ $attributes->class([
        'inline-flex items-center gap-3 rounded-xl',
    ]) }}
>
    <img
        src="{{ asset('images/olaer/logo.png') }}"
        alt=""
        class="{{ $compact ? 'size-10' : 'size-12' }} shrink-0 object-contain"
        width="512"
        height="512"
    >

    <span class="grid min-w-0">
        <span class="font-public-display text-xl font-semibold leading-none tracking-tight">
            Olaer Spring Resort
        </span>
        <span class="mt-1 text-[0.64rem] font-semibold uppercase tracking-[0.2em] opacity-70">
            General Santos City
        </span>
    </span>
</a>

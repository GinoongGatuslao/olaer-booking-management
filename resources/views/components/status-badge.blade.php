@props([
    'status',
    'size' => 'sm',
])

@php
    $normalizedStatus = str($status)->trim()->lower()->toString();

    $color = \App\Support\StatusColor::for($normalizedStatus);
@endphp

<flux:badge
    :color="$color"
    :size="$size"
    {{ $attributes }}
>
    {{ $status }}
</flux:badge>

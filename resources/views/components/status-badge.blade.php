@props([
    'status',
    'size' => 'sm',
])

@php
    $normalizedStatus = str($status)->trim()->lower()->toString();

    $color = match ($normalizedStatus) {
        'active',
        'verified',
        'delivered',
        'completed',
        'cleared',
        'paid',
        'checked-out' => 'green',

        'checked-in' => 'emerald',

        'booked',
        'partially checked-in',
        'partially checked-out',
        'delivering',
        'in progress' => 'sky',

        'pending',
        'pending verification',
        'unpaid' => 'amber',

        'rejected',
        'payment rejected' => 'red',

        'cancelled',
        'no-show' => 'zinc',

        default => 'zinc',
    };
@endphp

<flux:badge
    :color="$color"
    :size="$size"
    {{ $attributes }}
>
    {{ $status }}
</flux:badge>

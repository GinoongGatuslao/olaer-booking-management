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
        'ready for check-out' => 'green',

        'checked-in' => 'emerald',

        'booked',
        'partially checked-in',
        'partially checked-out',
        'delivering',
        'in progress' => 'sky',
        'inspection pending' => 'sky',

        'pending',
        'pending verification',
        'unpaid',
        'inspection not requested' => 'amber',

        'rejected',
        'payment rejected',
        'with issues',
        'payment required' => 'red',

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

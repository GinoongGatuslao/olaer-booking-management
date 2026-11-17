<?php

namespace App\Support;

/**
 * Single source of truth for status badge colors.
 *
 * Only six colors are used, one per meaning:
 *   green  = success / done / available
 *   blue   = reserved / scheduled / moved to a later stage
 *   purple = in progress / currently in use
 *   amber  = waiting on someone or something
 *   red    = problem / rejected / stopped
 *   zinc   = neutral / not active (also the fallback for unknown values)
 *
 * The same status name always resolves to the same color on every page
 * (matching ignores case and hyphen/space differences). Colors are Flux UI
 * badge colors. The badge text still carries the exact status, so color is
 * never the only signal.
 */
class StatusColor
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        // GREEN: success, done, available, approved
        'active' => 'green',
        'available' => 'green',
        'verified' => 'green',
        'approved' => 'green',
        'confirmed' => 'green',
        'published' => 'green',
        'current' => 'green',
        'cleared' => 'green',
        'ready for check-out' => 'green',
        'checked-in' => 'green',
        'paid' => 'green',
        'delivered' => 'green',
        'completed' => 'green',
        'fulfilled' => 'green',
        'refunded' => 'green',
        'converted' => 'green',

        // BLUE: reserved, scheduled, or moved to a later stage
        'booked' => 'blue',
        'accepted' => 'blue',
        'rescheduled' => 'blue',
        'transferred' => 'blue',
        'extended' => 'blue',
        'checked-out' => 'blue',

        // PURPLE: in progress / currently in use
        'in progress' => 'purple',
        'delivering' => 'purple',
        'partially checked-in' => 'purple',
        'partially checked-out' => 'purple',
        'occupied' => 'purple',
        'in use' => 'purple',
        'inspection pending' => 'purple',
        'inspection in progress' => 'purple',
        'inspection completed' => 'purple',

        // AMBER: waiting on someone or something
        'pending' => 'amber',
        'pending verification' => 'amber',
        'awaiting payment' => 'amber',
        'unpaid' => 'amber',
        'payment due' => 'amber',
        'payment required' => 'amber',
        'inspection not requested' => 'amber',
        'needs inspection request' => 'amber',
        'waiting for inspection result' => 'amber',

        // RED: problem, rejected, or stopped
        'rejected' => 'red',
        'payment rejected' => 'red',
        'damage found' => 'red',
        'with issues' => 'red',
        'unavailable' => 'red',
        'undelivered' => 'red',
        'expired' => 'red',
        'cancelled' => 'red',
        'canceled' => 'red',
        'inspection cancelled' => 'red',
        'voided' => 'red',
        'no-show' => 'red',

        // GRAY: neutral, finished with, or not active
        'closed' => 'zinc',
        'draft' => 'zinc',
        'inactive' => 'zinc',
        'outdated' => 'zinc',
    ];

    public static function for(?string $status): string
    {
        $key = self::normalize($status);

        if (isset(self::MAP[$key])) {
            return self::MAP[$key];
        }

        // Any other "Inspection <status>" workflow stage.
        if (str_starts_with($key, 'inspection ')) {
            return 'purple';
        }

        return 'zinc';
    }

    private static function normalize(?string $status): string
    {
        $key = strtolower(trim((string) $status));

        // "No show" / "No-Show" / "Checked out" etc. all resolve the same.
        $key = preg_replace('/\s+/', ' ', $key) ?? $key;

        return match ($key) {
            'no show', 'noshow' => 'no-show',
            'checked in' => 'checked-in',
            'checked out' => 'checked-out',
            'partially checked in' => 'partially checked-in',
            'partially checked out' => 'partially checked-out',
            'ready for check out' => 'ready for check-out',
            default => $key,
        };
    }
}

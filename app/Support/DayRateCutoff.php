<?php

namespace App\Support;

use App\FacilitySchedulePolicy;
use Carbon\CarbonInterface;

/**
 * From 6:00 PM it is no longer daytime, so a cottage or function hall that
 * starts and ends today can only be taken on the Night rate: the Day and Both
 * (Day + Night) rates are closed. Rooms, and any future date, are not affected.
 */
final class DayRateCutoff
{
    public const HOUR = 18;

    public const MESSAGE = 'The Day and Both rates are closed for today from 6:00 PM. Choose the Night rate or another date.';

    /** Is the cutoff in effect for a facility starting (and ending) on this date? */
    public static function closedFor(
        FacilitySchedulePolicy $policy,
        string $checkIn,
        ?string $checkOut = null,
        ?CarbonInterface $now = null,
    ): bool {
        $now ??= now();

        if ($policy === FacilitySchedulePolicy::Overnight) {
            return false; // rooms
        }

        $today = $now->toDateString();

        return $checkIn === $today
            && ($checkOut === null || $checkOut === $today)
            && $now->hour >= self::HOUR;
    }

    /** Can this rate no longer be chosen? */
    public static function blocks(
        FacilitySchedulePolicy $policy,
        string $rateCode,
        string $checkIn,
        ?string $checkOut = null,
        ?CarbonInterface $now = null,
    ): bool {
        return in_array(strtoupper($rateCode), ['DAY', 'BOTH'], true)
            && self::closedFor($policy, $checkIn, $checkOut, $now);
    }
}

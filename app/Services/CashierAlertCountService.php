<?php

namespace App\Services;

use App\Models\BookingDetail;
use App\Models\Reservation;
use Illuminate\Support\Carbon;

/**
 * Time-based numbers shown on the cashier sidebar.
 *
 * - Reservations: today's active reservations, counted from the time their rate starts.
 * - Bookings: bookings whose check-in time is within the next 30 minutes, plus overnight
 *   room bookings that have no check-in time (counted from 1:30 PM on their check-in date).
 *
 * Nothing is counted once the check-in date is no longer today.
 */
class CashierAlertCountService
{
    /** Minutes after midnight from which today's reservations of a rate start counting. */
    private const RESERVATION_START_MINUTE = [
        'DAY' => 6 * 60,          // 6:00 AM
        'BOTH' => 6 * 60,         // 6:00 AM
        'NIGHT' => 18 * 60,       // 6:00 PM
        'OVERNIGHT' => 13 * 60 + 30, // 1:30 PM (hotel rooms)
    ];

    /** Start time for any other rate (for example whole-day function halls). */
    private const RESERVATION_DEFAULT_START_MINUTE = 6 * 60;

    /** Overnight room bookings without a saved check-in time start counting at 1:30 PM. */
    private const OVERNIGHT_NO_TIME_START_MINUTE = 13 * 60 + 30;

    /** A booking counts from this many minutes before its check-in time until that time. */
    private const BOOKING_MINUTES_BEFORE = 30;

    /**
     * @return array{reservations_today: int, bookings_arriving: int}
     */
    public function counts(?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        return [
            'reservations_today' => $this->reservationsDueToday($now),
            'bookings_arriving' => $this->bookingsArrivingSoon($now),
        ];
    }

    /**
     * Active, still valid reservations for today. Day and Both rates start counting at
     * 6 AM, Night at 6 PM and overnight rooms at 1:30 PM. Cancelled, converted and no-show
     * reservations are never counted, and a reservation is gone once its date has passed.
     */
    public function reservationsDueToday(Carbon $now): int
    {
        $minute = ((int) $now->format('G')) * 60 + (int) $now->format('i');
        $knownRates = array_keys(self::RESERVATION_START_MINUTE);

        if ($minute < min(self::RESERVATION_DEFAULT_START_MINUTE, ...array_values(self::RESERVATION_START_MINUTE))) {
            return 0;
        }

        return Reservation::query()
            ->where('status', 'Active')
            ->whereHas('details', function ($details) use ($now, $minute, $knownRates): void {
                $details
                    ->whereDate('check_in_date', $now->toDateString())
                    ->whereNotIn('status', ['Cancelled', 'Converted', 'No-show'])
                    ->where(function ($rate) use ($minute, $knownRates): void {
                        $rate->whereRaw('1 = 0');

                        foreach (self::RESERVATION_START_MINUTE as $code => $start) {
                            if ($minute >= $start) {
                                $rate->orWhere('rate_code', $code);
                            }
                        }

                        if ($minute >= self::RESERVATION_DEFAULT_START_MINUTE) {
                            $rate->orWhere(function ($other) use ($knownRates): void {
                                $other->whereNotIn('rate_code', $knownRates)
                                    ->orWhereNull('rate_code');
                            });
                        }
                    });
            })
            ->count();
    }

    /**
     * Bookings waiting to be checked in:
     * - with a check-in time: from 30 minutes before it until that time passes;
     * - overnight rooms without a check-in time: from 1:30 PM on the check-in date until
     *   the end of that day (not counted the next day).
     */
    public function bookingsArrivingSoon(Carbon $now): int
    {
        $waitingDetail = fn () => BookingDetail::query()
            ->whereIn('status', ['Booked', 'Rescheduled', 'Transferred', 'Extended'])
            ->whereHas('booking', function ($booking): void {
                $booking->whereIn('status', ['Booked', 'Partially Checked-in', 'Partially Checked-out']);
            });

        $withTime = $waitingDetail()
            ->whereNotNull('check_in_time')
            ->whereRaw('TIMESTAMP(check_in_date, check_in_time) BETWEEN ? AND ?', [
                $now->toDateTimeString(),
                $now->copy()->addMinutes(self::BOOKING_MINUTES_BEFORE)->toDateTimeString(),
            ])
            ->pluck('booking_id');

        $minute = ((int) $now->format('G')) * 60 + (int) $now->format('i');

        $overnightWithoutTime = collect();

        if ($minute >= self::OVERNIGHT_NO_TIME_START_MINUTE) {
            $overnightWithoutTime = $waitingDetail()
                ->whereNull('check_in_time')
                ->where('rate_code', 'OVERNIGHT')
                ->whereDate('check_in_date', $now->toDateString())
                ->pluck('booking_id');
        }

        // A booking with several facilities is counted once.
        return $withTime->merge($overnightWithoutTime)->unique()->count();
    }
}

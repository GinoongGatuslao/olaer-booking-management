<?php

namespace App\Services;

use App\Models\Facility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Numbers behind the admin dashboard charts. The whole set is cached for a few
 * minutes because the revenue split works out every booking's payments.
 */
class AdminChartsService
{
    public const RANGES = [
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        '90d' => 'Last 90 days (weekly)',
        '12m' => 'Last 12 months (monthly)',
    ];

    public const REVENUE_CATEGORIES = ['Booking', 'Reservation', 'Amenity Request', 'Entrance Slip', 'Fines'];

    private const CACHE_SECONDS = 300;

    public function __construct(
        private readonly OutstandingBreakdownService $breakdown,
    ) {}

    /** @return array<string, mixed> */
    public function charts(string $range): array
    {
        $range = array_key_exists($range, self::RANGES) ? $range : '30d';

        return Cache::remember(
            'admin-dashboard-charts:'.$range.':'.today()->toDateString(),
            self::CACHE_SECONDS,
            fn (): array => $this->build($range),
        );
    }

    /** @return array<string, mixed> */
    private function build(string $range): array
    {
        [$from, $to, $buckets] = $this->window($range);

        return [
            'labels' => array_column($buckets, 'label'),
            'status' => $this->bookingStatus($from->toDateString(), $to->toDateString()),
            'trend' => $this->bookingsAndOccupancy($buckets, $from, $to),
            'revenue' => $this->revenueByCategory($buckets, $from->toDateString(), $to->toDateString()),
            'sources' => $this->sourceTrend($buckets, $from->toDateString(), $to->toDateString()),
            'demand' => $this->weekdayDemand($from->toDateString(), $to->toDateString()),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon, 2: list<array{start: Carbon, end: Carbon, label: string}>} */
    private function window(string $range): array
    {
        $to = Carbon::today();

        [$from, $step] = match ($range) {
            '7d' => [$to->copy()->subDays(6), 'day'],
            '90d' => [$to->copy()->subDays(89), 'week'],
            '12m' => [$to->copy()->subMonths(11)->startOfMonth(), 'month'],
            default => [$to->copy()->subDays(29), 'day'],
        };

        $buckets = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $end = match ($step) {
                'week' => $cursor->copy()->addDays(6),
                'month' => $cursor->copy()->endOfMonth()->startOfDay(),
                default => $cursor->copy(),
            };

            if ($end->gt($to)) {
                $end = $to->copy();
            }

            $buckets[] = [
                'start' => $cursor->copy(),
                'end' => $end,
                'label' => $step === 'month' ? $cursor->format('M Y') : $cursor->format('M j'),
            ];

            $cursor = $end->copy()->addDay()->startOfDay();
        }

        return [$from, $to, $buckets];
    }

    /** @param list<array{start: Carbon, end: Carbon, label: string}> $buckets */
    private function bucketIndex(string $date, array $buckets): ?int
    {
        $day = Carbon::parse(substr($date, 0, 10))->startOfDay();

        foreach ($buckets as $index => $bucket) {
            if ($day->gte($bucket['start']) && $day->lte($bucket['end'])) {
                return $index;
            }
        }

        return null;
    }

    /** Confirmed = booked and waiting to arrive; Checked-in includes partly checked-in stays. */
    private function bookingStatus(string $from, string $to): array
    {
        $counts = DB::table('tbl_booking')
            ->selectRaw('status, COUNT(*) AS total')
            ->whereBetween('booking_date', [$from, $to])
            ->groupBy('status')
            ->pluck('total', 'status');

        $sum = fn (array $statuses): int => (int) collect($statuses)
            ->sum(fn (string $status): int => (int) ($counts[$status] ?? 0));

        return [
            'labels' => ['Confirmed', 'Checked-in', 'Checked-out'],
            'values' => [
                $sum(['Booked', 'Rescheduled', 'Transferred', 'Extended']),
                $sum(['Checked-in', 'Partially Checked-in', 'Partially Checked-out']),
                $sum(['Checked-out']),
            ],
        ];
    }

    /**
     * Bookings made per period (cancelled ones left out), the occupancy rate
     * (occupied facility-days over available facility-days) and the average daily
     * rate (facility revenue per occupied facility-day).
     *
     * @param  list<array{start: Carbon, end: Carbon, label: string}>  $buckets
     */
    private function bookingsAndOccupancy(array $buckets, Carbon $from, Carbon $to): array
    {
        $bookings = array_fill(0, count($buckets), 0);

        DB::table('tbl_booking')
            ->selectRaw('booking_date, COUNT(*) AS total')
            ->where('status', '!=', 'Cancelled')
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('booking_date')
            ->get()
            ->each(function (object $row) use (&$bookings, $buckets): void {
                $index = $this->bucketIndex((string) $row->booking_date, $buckets);

                if ($index !== null) {
                    $bookings[$index] += (int) $row->total;
                }
            });

        $facilityCount = max(1, Facility::query()->where('facility_status', '!=', 'Unavailable')->count());

        $occupied = [];
        $dayRevenue = [];

        $details = DB::table('tbl_booking_details as d')
            ->join('tbl_booking as b', 'b.booking_id', '=', 'd.booking_id')
            ->whereNotNull('d.facility_id')
            ->where('d.status', '!=', 'Cancelled')
            ->where('b.status', '!=', 'Cancelled')
            ->whereDate('d.check_in_date', '<=', $to->toDateString())
            ->whereDate('d.check_out_date', '>=', $from->toDateString())
            ->get(['d.facility_id', 'd.check_in_date', 'd.check_out_date', 'd.line_total']);

        foreach ($details as $detail) {
            $in = Carbon::parse(substr((string) $detail->check_in_date, 0, 10))->startOfDay();
            $out = Carbon::parse(substr((string) $detail->check_out_date, 0, 10))->startOfDay();

            // An overnight stay does not occupy its check-out day; a same-day stay occupies that day.
            $last = $out->gt($in) ? $out->copy()->subDay() : $in->copy();
            $days = intdiv($last->getTimestamp() - $in->getTimestamp(), 86400) + 1;
            $perDay = (float) $detail->line_total / max($days, 1);

            for ($day = $in->copy(); $day->lte($last); $day->addDay()) {
                if ($day->lt($from) || $day->gt($to)) {
                    continue;
                }

                $key = $day->toDateString();
                $occupied[$key][(int) $detail->facility_id] = true;
                $dayRevenue[$key] = ($dayRevenue[$key] ?? 0) + $perDay;
            }
        }

        $occupancy = [];
        $adr = [];

        foreach ($buckets as $bucket) {
            $facilityDays = 0;
            $revenue = 0.0;
            $availableDays = 0;

            for ($day = $bucket['start']->copy(); $day->lte($bucket['end']); $day->addDay()) {
                $key = $day->toDateString();
                $facilityDays += count($occupied[$key] ?? []);
                $revenue += $dayRevenue[$key] ?? 0;
                $availableDays++;
            }

            $occupancy[] = round(min(100, $facilityDays / ($facilityCount * max($availableDays, 1)) * 100), 1);
            $adr[] = $facilityDays > 0 ? round($revenue / $facilityDays, 2) : 0;
        }

        return [
            'bookings' => $bookings,
            'occupancy' => $occupancy,
            'adr' => $adr,
            'facility_count' => $facilityCount,
        ];
    }

    /**
     * Verified payments per period, split by what they paid for. A booking payment is
     * divided across the booking's facility, amenity and fine charges the same way the
     * receipt shows it. Refunds given back reduce Reservation on the day they were given.
     *
     * @param  list<array{start: Carbon, end: Carbon, label: string}>  $buckets
     * @return array<string, list<float>>
     */
    private function revenueByCategory(array $buckets, string $from, string $to): array
    {
        $series = array_fill_keys(self::REVENUE_CATEGORIES, array_fill(0, count($buckets), 0.0));

        $add = function (string $category, string $date, float $amount) use (&$series, $buckets): void {
            $index = $this->bucketIndex($date, $buckets);

            if ($index !== null) {
                $series[$category][$index] += $amount;
            }
        };

        $payments = DB::table('tbl_payment')
            ->whereRaw('LOWER(payment_status) = ?', ['verified'])
            ->whereBetween('date_paid', [$from, $to])
            ->get(['payment_id', 'booking_id', 'reservation_id', 'entrance_slip_id', 'amount_paid', 'date_paid']);

        $bookingGroups = [];

        foreach ($payments as $payment) {
            $date = substr((string) $payment->date_paid, 0, 10);
            $amount = (float) $payment->amount_paid;

            if ($payment->booking_id !== null) {
                $bookingId = (int) $payment->booking_id;

                if (! array_key_exists($bookingId, $bookingGroups)) {
                    try {
                        $bookingGroups[$bookingId] = $this->breakdown->bookingPaymentGroups($bookingId);
                    } catch (Throwable $exception) {
                        report($exception);
                        $bookingGroups[$bookingId] = [];
                    }
                }

                $groups = $bookingGroups[$bookingId][(int) $payment->payment_id] ?? null;

                if ($groups === null) {
                    $add('Booking', $date, $amount);

                    continue;
                }

                foreach ($groups as $group => $applied) {
                    $add(match ($group) {
                        'Amenity' => 'Amenity Request',
                        'Fine' => 'Fines',
                        default => 'Booking',
                    }, $date, (float) $applied);
                }

                continue;
            }

            if ($payment->reservation_id !== null) {
                $add('Reservation', $date, $amount);

                continue;
            }

            if ($payment->entrance_slip_id !== null) {
                $add('Entrance Slip', $date, $amount);
            }
        }

        try {
            DB::table('tbl_refund_request')
                ->whereNotNull('refunded_at')
                ->whereDate('refunded_at', '>=', $from)
                ->whereDate('refunded_at', '<=', $to)
                ->get(['refunded_amount', 'refunded_at'])
                ->each(fn (object $row) => $add(
                    'Reservation',
                    substr((string) $row->refunded_at, 0, 10),
                    -1 * (float) $row->refunded_amount,
                ));
        } catch (Throwable $exception) {
            report($exception);
        }

        return array_map(
            fn (array $values): array => array_map(fn (float $value): float => round($value, 2), $values),
            $series,
        );
    }

    /**
     * Bookings and reservations made per period, website (made by the guest, no staff
     * account) against cashier. Bookings that came from a reservation are left out, since
     * the reservation already counts.
     *
     * @param  list<array{start: Carbon, end: Carbon, label: string}>  $buckets
     * @return array<string, list<int>>
     */
    private function sourceTrend(array $buckets, string $from, string $to): array
    {
        $empty = array_fill(0, count($buckets), 0);

        $series = [
            'Website bookings' => $empty,
            'Cashier bookings' => $empty,
            'Website reservations' => $empty,
            'Cashier reservations' => $empty,
        ];

        $website = 'CASE WHEN user_id IS NULL THEN 1 ELSE 0 END';

        $rows = [
            'bookings' => DB::table('tbl_booking')
                ->selectRaw("booking_date AS made_on, {$website} AS website, COUNT(*) AS total")
                ->whereNull('reservation_id')
                ->whereBetween('booking_date', [$from, $to])
                ->groupBy('booking_date', DB::raw($website))
                ->get(),
            'reservations' => DB::table('tbl_reservation')
                ->selectRaw("reservation_date AS made_on, {$website} AS website, COUNT(*) AS total")
                ->whereBetween('reservation_date', [$from, $to])
                ->groupBy('reservation_date', DB::raw($website))
                ->get(),
        ];

        foreach ($rows as $type => $list) {
            foreach ($list as $row) {
                $index = $this->bucketIndex((string) $row->made_on, $buckets);

                if ($index === null) {
                    continue;
                }

                $key = ((int) $row->website === 1 ? 'Website ' : 'Cashier ').$type;
                $series[$key][$index] += (int) $row->total;
            }
        }

        return $series;
    }

    /**
     * Rooms and cottages booked or reserved, by the day of the week the stay starts.
     * Cancelled ones are left out, and so are converted reservations (the booking counts).
     *
     * @return array{days: list<string>, Room: list<int>, Cottage: list<int>}
     */
    private function weekdayDemand(string $from, string $to): array
    {
        $result = [
            'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'Room' => array_fill(0, 7, 0),
            'Cottage' => array_fill(0, 7, 0),
        ];

        $bookings = DB::table('tbl_booking_details as d')
            ->join('tbl_booking as b', 'b.booking_id', '=', 'd.booking_id')
            ->join('tbl_facility as f', 'f.facility_id', '=', 'd.facility_id')
            ->join('tbl_facility_type as t', 't.facility_type_id', '=', 'f.facility_type_id')
            ->whereIn('t.facility_type', ['Room', 'Cottage'])
            ->where('d.status', '!=', 'Cancelled')
            ->where('b.status', '!=', 'Cancelled')
            ->whereBetween('d.check_in_date', [$from, $to])
            ->get(['t.facility_type', 'd.check_in_date']);

        $reservations = DB::table('tbl_reservation_details as d')
            ->join('tbl_reservation as r', 'r.reservation_id', '=', 'd.reservation_id')
            ->join('tbl_facility as f', 'f.facility_id', '=', 'd.facility_id')
            ->join('tbl_facility_type as t', 't.facility_type_id', '=', 'f.facility_type_id')
            ->whereIn('t.facility_type', ['Room', 'Cottage'])
            ->whereNotIn('r.status', ['Cancelled', 'Converted'])
            ->whereBetween('d.check_in_date', [$from, $to])
            ->get(['t.facility_type', 'd.check_in_date']);

        foreach ($bookings->concat($reservations) as $row) {
            $weekday = Carbon::parse(substr((string) $row->check_in_date, 0, 10))->dayOfWeekIso - 1;

            $result[(string) $row->facility_type][$weekday]++;
        }

        return $result;
    }
}

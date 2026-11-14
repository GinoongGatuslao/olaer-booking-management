<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FinancialReportMetricsService
{
    /**
     * @return array{
     *   scope:string,
     *   cashier_user_id:?int,
     *   date_from:?string,
     *   date_to:?string,
     *   verified_revenue:float,       net of refunds given in the range
     *   gross_revenue:float,          verified payments before refunds
     *   refunded_total:float,
     *   refund_count:int,
     *   verified_payment_count:int,
     *   booking_revenue:float,
     *   reservation_revenue:float,
     *   entrance_revenue:float,
     *   cash_revenue:float,
     *   gcash_revenue:float,
     *   other_mode_revenue:float,
     *   outstanding_booking_balance:float,
     *   outstanding_reservation_balance:float,
     *   outstanding_entrance_balance:float,
     *   total_outstanding_balance:float
     * }
     */
    public function summary(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $cashierUserId = null,
    ): array {
        [$from, $to] = $this->normalizeRange(
            $dateFrom,
            $dateTo,
        );

        $payments = $this->verifiedPaymentsQuery(
            $from,
            $to,
            $cashierUserId,
        );

        $grossRevenue = $this->sumAmount(
            clone $payments,
        );

        // Refunds given back to guests in the range reduce revenue (all of
        // them belong to reservations).
        $refunds = $this->refundsQuery($from, $to, $cashierUserId);
        $refundedTotal = $this->sumRefunds(clone $refunds);
        $refundCount = (int) (clone $refunds)->count('rr.refund_request_id');
        $cashRefunds = $this->sumRefunds((clone $refunds)->whereRaw(
            'LOWER(COALESCE(rm.mode_of_payment, ?)) = ?',
            ['', 'cash'],
        ));
        $gcashRefunds = $this->sumRefunds((clone $refunds)->whereRaw(
            'LOWER(COALESCE(rm.mode_of_payment, ?)) = ?',
            ['', 'gcash'],
        ));

        $verifiedRevenue = round($grossRevenue - $refundedTotal, 2);

        // The three sources must not overlap, or they will not add up to net revenue.
        // The payment taken when a reservation is converted carries both a booking_id
        // and a reservation_id, so it is counted once, under Booking. Reservation
        // revenue is only money still held on a reservation, less refunds given back.
        $bookingRevenue = $this->sumAmount(
            (clone $payments)->whereNotNull(
                'p.booking_id',
            ),
        );

        $reservationRevenue = round($this->sumAmount(
            (clone $payments)
                ->whereNull('p.booking_id')
                ->whereNotNull('p.reservation_id'),
        ) - $refundedTotal, 2);

        $entranceRevenue = $this->sumAmount(
            (clone $payments)->whereNotNull(
                'p.entrance_slip_id',
            ),
        );

        $cashRevenue = round($this->sumAmount(
            (clone $payments)->whereRaw(
                'LOWER(COALESCE(m.mode_of_payment, ?)) = ?',
                ['', 'cash'],
            ),
        ) - $cashRefunds, 2);

        $gcashRevenue = round($this->sumAmount(
            (clone $payments)->whereRaw(
                'LOWER(COALESCE(m.mode_of_payment, ?)) = ?',
                ['', 'gcash'],
            ),
        ) - $gcashRefunds, 2);

        $outstandingBooking = $this->sumOutstanding(
            DB::table('tbl_booking')
                ->whereIn('status', [
                    'Booked',
                    'Checked-in',
                    'Partially Checked-in',
                    'Partially Checked-out',
                ]),
        );

        $outstandingReservation =
            $this->sumOutstanding(
                DB::table('tbl_reservation')
                    ->where('status', 'Active'),
            );

        $outstandingEntrance = $this->sumOutstanding(
            DB::table('tbl_entrance_slip')
                ->where('status', 'Unpaid'),
        );

        return [
            'scope' =>
                $cashierUserId === null
                    ? 'all'
                    : 'cashier',
            'cashier_user_id' => $cashierUserId,
            'date_from' => $from,
            'date_to' => $to,
            'verified_revenue' => $verifiedRevenue,
            'gross_revenue' => $grossRevenue,
            'refunded_total' => $refundedTotal,
            'refund_count' => $refundCount,
            'verified_payment_count' => (int) (
                clone $payments
            )->count('p.payment_id'),
            'booking_revenue' => $bookingRevenue,
            'reservation_revenue' =>
                $reservationRevenue,
            'entrance_revenue' => $entranceRevenue,
            'cash_revenue' => $cashRevenue,
            'gcash_revenue' => $gcashRevenue,
            'other_mode_revenue' => round(
                $verifiedRevenue
                - $cashRevenue
                - $gcashRevenue,
                2,
            ),
            'outstanding_booking_balance' =>
                $outstandingBooking,
            'outstanding_reservation_balance' =>
                $outstandingReservation,
            'outstanding_entrance_balance' =>
                $outstandingEntrance,
            'total_outstanding_balance' => round(
                $outstandingBooking
                + $outstandingReservation
                + $outstandingEntrance,
                2,
            ),
        ];
    }

    /**
     * @return array<int, array{
     *   date:string,
     *   payment_count:int,
     *   revenue:float
     * }>
     */
    public function dailyVerifiedRevenue(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $cashierUserId = null,
    ): array {
        [$from, $to] = $this->normalizeRange(
            $dateFrom,
            $dateTo,
        );

        $days = $this->verifiedPaymentsQuery(
            $from,
            $to,
            $cashierUserId,
        )
            ->selectRaw(
                'p.date_paid as revenue_date',
            )
            ->selectRaw(
                'COUNT(p.payment_id) as payment_count',
            )
            ->selectRaw(
                'SUM(p.amount_paid) as revenue',
            )
            ->groupBy('p.date_paid')
            ->get()
            ->mapWithKeys(
                fn (object $row): array => [
                    (string) $row->revenue_date => [
                        'date' => (string) $row->revenue_date,
                        'payment_count' => (int) $row->payment_count,
                        'gross' => (float) $row->revenue,
                        'refunded' => 0.0,
                    ],
                ],
            )
            ->all();

        // Refunds reduce the revenue of the day they were given.
        $refundDays = $this->refundsQuery($from, $to, $cashierUserId)
            ->selectRaw('DATE(rr.refunded_at) as refund_date')
            ->selectRaw('SUM(rr.refunded_amount) as refunded')
            ->groupBy(DB::raw('DATE(rr.refunded_at)'))
            ->get();

        foreach ($refundDays as $row) {
            $date = (string) $row->refund_date;

            $days[$date] ??= [
                'date' => $date,
                'payment_count' => 0,
                'gross' => 0.0,
                'refunded' => 0.0,
            ];
            $days[$date]['refunded'] = (float) $row->refunded;
        }

        ksort($days);

        return array_values(array_map(
            fn (array $day): array => [
                'date' => $day['date'],
                'payment_count' => $day['payment_count'],
                'revenue' => round($day['gross'] - $day['refunded'], 2),
                'gross' => round($day['gross'], 2),
                'refunded' => round($day['refunded'], 2),
            ],
            $days,
        ));
    }

    /** Refunds given back to guests (cashier released them) within the range. */
    private function refundsQuery(
        ?string $dateFrom,
        ?string $dateTo,
        ?int $cashierUserId,
    ): Builder {
        return DB::table('tbl_refund_request as rr')
            ->leftJoin(
                'tbl_mode_of_payment as rm',
                'rm.mode_of_payment_id',
                '=',
                'rr.refund_mode_of_payment_id',
            )
            ->whereNotNull('rr.refunded_at')
            ->when(
                $cashierUserId !== null,
                fn (Builder $query): Builder => $query->where('rr.refunded_by_user_id', $cashierUserId),
            )
            ->when(
                $dateFrom !== null,
                fn (Builder $query): Builder => $query->whereDate('rr.refunded_at', '>=', $dateFrom),
            )
            ->when(
                $dateTo !== null,
                fn (Builder $query): Builder => $query->whereDate('rr.refunded_at', '<=', $dateTo),
            );
    }

    /** Refunds the cashier actually gave back within the range (for dashboards). */
    public function refundedTotal(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $cashierUserId = null,
    ): float {
        [$from, $to] = $this->normalizeRange($dateFrom, $dateTo);

        return $this->sumRefunds($this->refundsQuery($from, $to, $cashierUserId));
    }

    private function sumRefunds(Builder $query): float
    {
        return round((float) $query->sum('rr.refunded_amount'), 2);
    }

    private function verifiedPaymentsQuery(
        ?string $dateFrom,
        ?string $dateTo,
        ?int $cashierUserId,
    ): Builder {
        return DB::table('tbl_payment as p')
            ->leftJoin(
                'tbl_mode_of_payment as m',
                'm.mode_of_payment_id',
                '=',
                'p.mode_of_payment_id',
            )
            ->whereRaw(
                'LOWER(p.payment_status) = ?',
                ['verified'],
            )
            ->when(
                $cashierUserId !== null,
                function (
                    Builder $query,
                ) use (
                    $cashierUserId,
                ): void {
                    $query->where(
                        function (
                            Builder $query,
                        ) use (
                            $cashierUserId,
                        ): void {
                            $query
                                ->where(
                                    'p.user_id',
                                    $cashierUserId,
                                )
                                ->orWhere(
                                    'p.verified_by_user_id',
                                    $cashierUserId,
                                );
                        },
                    );
                },
            )
            ->when(
                $dateFrom !== null,
                fn (Builder $query): Builder =>
                    $query->whereDate(
                        'p.date_paid',
                        '>=',
                        $dateFrom,
                    ),
            )
            ->when(
                $dateTo !== null,
                fn (Builder $query): Builder =>
                    $query->whereDate(
                        'p.date_paid',
                        '<=',
                        $dateTo,
                    ),
            );
    }

    private function sumAmount(Builder $query): float
    {
        return round(
            (float) $query->sum('p.amount_paid'),
            2,
        );
    }

    private function sumOutstanding(
        Builder $query,
    ): float {
        return round(
            (float) $query
                ->where('amount_due', '>', 0)
                ->sum('amount_due'),
            2,
        );
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function normalizeRange(
        ?string $dateFrom,
        ?string $dateTo,
    ): array {
        $from = $this->normalizeDate($dateFrom);
        $to = $this->normalizeDate($dateTo);

        if (
            $from !== null
            && $to !== null
            && $from > $to
        ) {
            throw new InvalidArgumentException(
                'Report start date cannot be after the end date.',
            );
        }

        return [$from, $to];
    }

    private function normalizeDate(
        ?string $value,
    ): ?string {
        if (
            $value === null
            || trim($value) === ''
        ) {
            return null;
        }

        try {
            return Carbon::parse(
                $value,
            )->toDateString();
        } catch (\Throwable) {
            throw new InvalidArgumentException(
                'Use a valid report date.',
            );
        }
    }
}

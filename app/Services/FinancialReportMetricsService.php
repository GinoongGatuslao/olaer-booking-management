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
     *   verified_revenue:float,
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

        $verifiedRevenue = $this->sumAmount(
            clone $payments,
        );

        $bookingRevenue = $this->sumAmount(
            (clone $payments)->whereNotNull(
                'p.booking_id',
            ),
        );

        $reservationRevenue = $this->sumAmount(
            (clone $payments)->whereNotNull(
                'p.reservation_id',
            ),
        );

        $entranceRevenue = $this->sumAmount(
            (clone $payments)->whereNotNull(
                'p.entrance_slip_id',
            ),
        );

        $cashRevenue = $this->sumAmount(
            (clone $payments)->whereRaw(
                'LOWER(COALESCE(m.mode_of_payment, ?)) = ?',
                ['', 'cash'],
            ),
        );

        $gcashRevenue = $this->sumAmount(
            (clone $payments)->whereRaw(
                'LOWER(COALESCE(m.mode_of_payment, ?)) = ?',
                ['', 'gcash'],
            ),
        );

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

        return $this->verifiedPaymentsQuery(
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
            ->orderBy('p.date_paid')
            ->get()
            ->map(
                fn (object $row): array => [
                    'date' => (string) $row->revenue_date,
                    'payment_count' =>
                        (int) $row->payment_count,
                    'revenue' => round(
                        (float) $row->revenue,
                        2,
                    ),
                ],
            )
            ->all();
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

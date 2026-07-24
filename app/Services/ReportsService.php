<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\EntranceSlip;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\GuestFine;
use App\Models\Payment;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportsService
{
    public function __construct(
        private readonly FinancialReportMetricsService $financialMetrics,
    ) {}
    public function revenueReport(
        string $startDate,
        string $endDate,
        ?int $cashierUserId = null,
        int $perPage = 25,
        string $search = '',
        string $pageName = 'reportPage',
        bool $allRows = false,
    ): array {
        $base = Payment::query()
            ->whereDate('date_paid', '>=', $startDate)
            ->whereDate('date_paid', '<=', $endDate)
            ->whereRaw(
                'LOWER(payment_status) = ?',
                ['verified'],
            );

        if ($cashierUserId !== null) {
            $base->where(function (Builder $query) use ($cashierUserId): void {
                $query->where('user_id', $cashierUserId)
                    ->orWhere('verified_by_user_id', $cashierUserId);
            });
        }

        $this->applyRevenueSearch($base, $search);

        $count = (clone $base)->count();
        $total = round((float) (clone $base)->sum('amount_paid'), 2);

        $byMode = (clone $base)
            ->leftJoin(
                'tbl_mode_of_payment',
                'tbl_mode_of_payment.mode_of_payment_id',
                '=',
                'tbl_payment.mode_of_payment_id',
            )
            ->selectRaw(
                "COALESCE(tbl_mode_of_payment.mode_of_payment, 'Unknown') as payment_mode"
            )
            ->selectRaw('SUM(tbl_payment.amount_paid) as total_amount')
            ->groupBy('tbl_mode_of_payment.mode_of_payment')
            ->orderBy('payment_mode')
            ->pluck('total_amount', 'payment_mode')
            ->map(fn (mixed $amount): float => round((float) $amount, 2));

        $rowsQuery = (clone $base)
            ->with([
                'modeOfPayment',
                'user',
                'verifier',
                'booking.guest',
                'reservation.guest',
                'entranceSlip.guest',
            ])
            ->orderBy('date_paid')
            ->orderBy('payment_id');

        $financialMetrics =
            $this->financialMetrics->summary(
                $startDate,
                $endDate,
                $cashierUserId,
            );

        $dailyRevenue =
            $this->financialMetrics
                ->dailyVerifiedRevenue(
                    $startDate,
                    $endDate,
                    $cashierUserId,
                );

        return [
            'rows' => $this->paginateOrGet(
                $rowsQuery,
                $perPage,
                $pageName,
                $allRows,
            ),
            'total' => $total,
            'by_mode' => $byMode,
            'count' => $count,
            'financial_metrics' =>
                $financialMetrics,
            'daily_revenue' => $dailyRevenue,
            'show_outstanding_metrics' =>
                $cashierUserId === null,
        ];
    }

    public function bookingSummaryReport(
        string $startDate,
        string $endDate,
        ?int $cashierUserId = null,
        int $perPage = 25,
        string $search = '',
        string $pageName = 'reportPage',
        bool $allRows = false,
    ): array {
        $base = Booking::query()
            ->whereDate('booking_date', '>=', $startDate)
            ->whereDate('booking_date', '<=', $endDate);

        if ($cashierUserId !== null) {
            $base->where('user_id', $cashierUserId);
        }

        $this->applyBookingSearch($base, $search);

        $count = (clone $base)->count();
        $totalPrice = round(
            (float) (clone $base)->sum('total_price'),
            2,
        );
        $totalDue = round(
            (float) (clone $base)->sum('amount_due'),
            2,
        );

        $rowsQuery = (clone $base)
            ->with([
                'guest',
                'user',
                'details.facility.facilityType',
                'payments.modeOfPayment',
            ])
            ->orderBy('booking_date')
            ->orderBy('booking_id');

        return [
            'rows' => $this->paginateOrGet(
                $rowsQuery,
                $perPage,
                $pageName,
                $allRows,
            ),
            'total_price' => $totalPrice,
            'total_due' => $totalDue,
            'count' => $count,
        ];
    }

    public function cancellationReport(
        string $startDate,
        string $endDate,
        int $perPage = 25,
        string $search = '',
        string $pageName = 'reportPage',
        bool $allRows = false,
    ): array {
        $base = Reservation::query()
            ->whereNotNull('cancelled_at')
            ->whereDate('cancelled_at', '>=', $startDate)
            ->whereDate('cancelled_at', '<=', $endDate);

        $this->applyCancellationSearch($base, $search);

        $count = (clone $base)->count();

        $rowsQuery = (clone $base)
            ->with([
                'guest',
                'user',
                'details.facility.facilityType',
            ])
            ->orderBy('cancelled_at')
            ->orderBy('reservation_id');

        return [
            'rows' => $this->paginateOrGet(
                $rowsQuery,
                $perPage,
                $pageName,
                $allRows,
            ),
            'count' => $count,
        ];
    }

    public function damagedAmenitiesReport(
        string $startDate,
        string $endDate,
        int $perPage = 25,
        string $search = '',
        string $pageName = 'reportPage',
        bool $allRows = false,
    ): array {
        $base = GuestFine::query()
            ->whereDate('date_checked', '>=', $startDate)
            ->whereDate('date_checked', '<=', $endDate);

        $this->applyDamagedAmenitySearch($base, $search);

        $count = (clone $base)->count();
        $totalCharge = round(
            (float) (clone $base)->sum('total_charge'),
            2,
        );

        $rowsQuery = (clone $base)
            ->with([
                'booking.guest',
                'fine.amenity.amenityName',
                'fine.damageType',
                'facility.facilityType',
                'reportedBy',
            ])
            ->orderBy('date_checked')
            ->orderBy('guest_fine_id');

        return [
            'rows' => $this->paginateOrGet(
                $rowsQuery,
                $perPage,
                $pageName,
                $allRows,
            ),
            'total_charge' => $totalCharge,
            'count' => $count,
        ];
    }

    public function availableFacilitiesReport(
        string $startDate,
        string $endDate,
        ?int $facilityTypeId = null,
        int $perPage = 25,
        string $search = '',
        string $pageName = 'reportPage',
        bool $allRows = false,
    ): array {
        $blockedFacilityIds = $this->blockedFacilityIds(
            $startDate,
            $endDate,
        );

        $base = Facility::query()
            ->where('facility_status', 'Available')
            ->when(
                $blockedFacilityIds !== [],
                fn (Builder $query) => $query->whereNotIn(
                    'facility_id',
                    $blockedFacilityIds,
                ),
            )
            ->when(
                $facilityTypeId !== null,
                fn (Builder $query) => $query->where(
                    'facility_type_id',
                    $facilityTypeId,
                ),
            );

        $this->applyFacilitySearch($base, $search);

        $count = (clone $base)->count();

        $byType = (clone $base)
            ->join(
                'tbl_facility_type',
                'tbl_facility_type.facility_type_id',
                '=',
                'tbl_facility.facility_type_id',
            )
            ->select('tbl_facility_type.facility_type')
            ->selectRaw('COUNT(tbl_facility.facility_id) as facility_count')
            ->groupBy('tbl_facility_type.facility_type')
            ->orderBy('tbl_facility_type.facility_type')
            ->pluck('facility_count', 'facility_type')
            ->map(fn (mixed $value): int => (int) $value);

        $rowsQuery = (clone $base)
            ->with([
                'facilityType',
                'prices',
            ])
            ->orderBy('facility_type_id')
            ->orderBy('facility_name');

        return [
            'rows' => $this->paginateOrGet(
                $rowsQuery,
                $perPage,
                $pageName,
                $allRows,
            ),
            'count' => $count,
            'by_type' => $byType,
        ];
    }

    public function facilityUtilizationReport(
        string $startDate,
        string $endDate,
        ?int $facilityTypeId = null,
        int $perPage = 25,
        string $search = '',
        string $pageName = 'reportPage',
        bool $allRows = false,
    ): array {
        $base = DB::table('tbl_booking_details')
            ->join(
                'tbl_booking',
                'tbl_booking.booking_id',
                '=',
                'tbl_booking_details.booking_id',
            )
            ->join(
                'tbl_facility',
                'tbl_facility.facility_id',
                '=',
                'tbl_booking_details.facility_id',
            )
            ->join(
                'tbl_facility_type',
                'tbl_facility_type.facility_type_id',
                '=',
                'tbl_facility.facility_type_id',
            )
            ->select([
                'tbl_facility.facility_id',
                'tbl_facility.facility_name',
                'tbl_facility.facility_size',
                'tbl_facility.capacity',
                'tbl_facility_type.facility_type',
                DB::raw(
                    'COUNT(tbl_booking_details.booking_details_id) as booking_count'
                ),
            ])
            ->whereDate('tbl_booking.booking_date', '>=', $startDate)
            ->whereDate('tbl_booking.booking_date', '<=', $endDate)
            ->whereNotIn(
                'tbl_booking.status',
                ['Cancelled', 'Canceled'],
            )
            ->whereNotIn(
                'tbl_booking_details.status',
                ['Cancelled', 'Canceled', 'Transferred'],
            )
            ->when(
                $facilityTypeId !== null,
                fn (QueryBuilder $query) => $query->where(
                    'tbl_facility.facility_type_id',
                    $facilityTypeId,
                ),
            )
            ->when(trim($search) !== '', function (QueryBuilder $query) use ($search): void {
                $like = '%'.trim($search).'%';

                $query->where(function (QueryBuilder $query) use ($like): void {
                    $query->where(
                        'tbl_facility.facility_name',
                        'like',
                        $like,
                    )
                        ->orWhere(
                            'tbl_facility.facility_size',
                            'like',
                            $like,
                        )
                        ->orWhere(
                            'tbl_facility.capacity',
                            'like',
                            $like,
                        )
                        ->orWhere(
                            'tbl_facility_type.facility_type',
                            'like',
                            $like,
                        );
                });
            })
            ->groupBy([
                'tbl_facility.facility_id',
                'tbl_facility.facility_name',
                'tbl_facility.facility_size',
                'tbl_facility.capacity',
                'tbl_facility_type.facility_type',
            ]);

        $summaryRows = (clone $base)->get();
        $totalBookings = (int) $summaryRows->sum('booking_count');
        $count = $summaryRows->count();

        $rowsQuery = (clone $base)
            ->orderByDesc('booking_count')
            ->orderBy('tbl_facility.facility_name');

        $rows = $this->paginateOrGet(
            $rowsQuery,
            $perPage,
            $pageName,
            $allRows,
        );

        $transform = function (object $row) use ($totalBookings): object {
            $row->utilization_percentage = $totalBookings > 0
                ? round(
                    ((int) $row->booking_count / $totalBookings) * 100,
                    2,
                )
                : 0.00;

            return $row;
        };

        if ($rows instanceof Collection) {
            $rows = $rows->map($transform);
        } else {
            $rows->setCollection(
                $rows->getCollection()->map($transform)
            );
        }

        return [
            'rows' => $rows,
            'total_bookings' => $totalBookings,
            'count' => $count,
        ];
    }

    public function tourismEnterpriseMonthlyReport(
        int $year,
        int $month,
        int $perPage = 25,
        string $search = '',
        string $pageName = 'reportPage',
        bool $allRows = false,
    ): array {
        $start = CarbonImmutable::create($year, $month, 1)
            ->startOfMonth()
            ->toDateString();
        $end = CarbonImmutable::create($year, $month, 1)
            ->endOfMonth()
            ->toDateString();

        $base = EntranceSlip::query()
            ->whereDate('date_created', '>=', $start)
            ->whereDate('date_created', '<=', $end)
            ->where('status', 'Paid');

        $this->applyTourismSearch($base, $search);

        $summary = (clone $base)
            ->selectRaw('COUNT(*) as slip_count')
            ->selectRaw('COALESCE(SUM(no_of_Male), 0) as male')
            ->selectRaw('COALESCE(SUM(no_of_Female), 0) as female')
            ->selectRaw('COALESCE(SUM(no_of_Tourist), 0) as tourist')
            ->selectRaw(
                'COALESCE(SUM(no_of_adult), 0)
                 + COALESCE(SUM(no_of_children), 0)
                 + COALESCE(SUM(no_of_PWD_SC), 0)
                 as total_guests'
            )
            ->first();

        $rowsQuery = (clone $base)
            ->with([
                'createdBy',
                'handledBy',
            ])
            ->orderBy('date_created')
            ->orderBy('entrance_slip_id');

        return [
            'rows' => $this->paginateOrGet(
                $rowsQuery,
                $perPage,
                $pageName,
                $allRows,
            ),
            'male' => (int) ($summary->male ?? 0),
            'female' => (int) ($summary->female ?? 0),
            'tourist' => (int) ($summary->tourist ?? 0),
            'total_guests' => (int) ($summary->total_guests ?? 0),
            'count' => (int) ($summary->slip_count ?? 0),
        ];
    }

    public function facilityTypes(): EloquentCollection
    {
        return FacilityType::query()
            ->orderBy('facility_type')
            ->get();
    }

    private function applyRevenueSearch(
        Builder $query,
        string $search,
    ): void {
        if (trim($search) === '') {
            return;
        }

        $like = '%'.trim($search).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('p_ref_no', 'like', $like)
                ->orWhere('reference_number', 'like', $like)
                ->orWhereHas('modeOfPayment', function (Builder $query) use ($like): void {
                    $query->where('mode_of_payment', 'like', $like);
                })
                ->orWhereHas('booking', function (Builder $query) use ($like): void {
                    $query->where('b_ref_no', 'like', $like)
                        ->orWhereHas('guest', function (Builder $query) use ($like): void {
                            $this->applyGuestSearch($query, $like);
                        });
                })
                ->orWhereHas('reservation', function (Builder $query) use ($like): void {
                    $query->where('r_ref_no', 'like', $like)
                        ->orWhereHas('guest', function (Builder $query) use ($like): void {
                            $this->applyGuestSearch($query, $like);
                        });
                })
                ->orWhereHas('entranceSlip.guest', function (Builder $query) use ($like): void {
                    $this->applyGuestSearch($query, $like);
                })
                ->orWhereHas('user', function (Builder $query) use ($like): void {
                    $this->applyUserSearch($query, $like);
                })
                ->orWhereHas('verifier', function (Builder $query) use ($like): void {
                    $this->applyUserSearch($query, $like);
                });
        });
    }

    private function applyBookingSearch(
        Builder $query,
        string $search,
    ): void {
        if (trim($search) === '') {
            return;
        }

        $like = '%'.trim($search).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('b_ref_no', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->orWhereHas('guest', function (Builder $query) use ($like): void {
                    $this->applyGuestSearch($query, $like);
                })
                ->orWhereHas('details.facility', function (Builder $query) use ($like): void {
                    $query->where('facility_name', 'like', $like);
                });
        });
    }

    private function applyCancellationSearch(
        Builder $query,
        string $search,
    ): void {
        if (trim($search) === '') {
            return;
        }

        $like = '%'.trim($search).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('r_ref_no', 'like', $like)
                ->orWhere('cancellation_reason', 'like', $like)
                ->orWhereHas('guest', function (Builder $query) use ($like): void {
                    $this->applyGuestSearch($query, $like);
                })
                ->orWhereHas('details.facility', function (Builder $query) use ($like): void {
                    $query->where('facility_name', 'like', $like);
                });
        });
    }

    private function applyDamagedAmenitySearch(
        Builder $query,
        string $search,
    ): void {
        if (trim($search) === '') {
            return;
        }

        $like = '%'.trim($search).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->whereHas('booking', function (Builder $query) use ($like): void {
                $query->where('b_ref_no', 'like', $like)
                    ->orWhereHas('guest', function (Builder $query) use ($like): void {
                        $this->applyGuestSearch($query, $like);
                    });
            })
                ->orWhereHas('facility', function (Builder $query) use ($like): void {
                    $query->where('facility_name', 'like', $like);
                })
                ->orWhereHas('fine', function (Builder $query) use ($like): void {
                    $query->where('situational_fine', 'like', $like)
                        ->orWhere(
                            'situational_fine_description',
                            'like',
                            $like,
                        )
                        ->orWhereHas('amenity', function (Builder $query) use ($like): void {
                            $query->where(
                                'amenity_description',
                                'like',
                                $like,
                            )
                                ->orWhereHas('amenityName', function (Builder $query) use ($like): void {
                                    $query->where(
                                        'amenity_name',
                                        'like',
                                        $like,
                                    );
                                });
                        })
                        ->orWhereHas('damageType', function (Builder $query) use ($like): void {
                            $query->where(
                                'damage_type',
                                'like',
                                $like,
                            );
                        });
                })
                ->orWhereHas('reportedBy', function (Builder $query) use ($like): void {
                    $this->applyUserSearch($query, $like);
                });
        });
    }

    private function applyFacilitySearch(
        Builder $query,
        string $search,
    ): void {
        if (trim($search) === '') {
            return;
        }

        $like = '%'.trim($search).'%';

        $query->where(function (Builder $query) use ($like): void {
            $query->where('facility_name', 'like', $like)
                ->orWhere('facility_size', 'like', $like)
                ->orWhere('capacity', 'like', $like)
                ->orWhereHas('facilityType', function (Builder $query) use ($like): void {
                    $query->where('facility_type', 'like', $like);
                });
        });
    }

    private function applyTourismSearch(
        Builder $query,
        string $search,
    ): void {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $numeric = ctype_digit($search) ? (int) $search : null;

        $query->where(function (Builder $query) use ($like, $numeric): void {
            $query->where('date_created', 'like', $like)
                ->orWhereHas('createdBy', function (Builder $query) use ($like): void {
                    $this->applyUserSearch($query, $like);
                })
                ->orWhereHas('handledBy', function (Builder $query) use ($like): void {
                    $this->applyUserSearch($query, $like);
                });

            if ($numeric !== null) {
                $query->orWhere('entrance_slip_id', $numeric);
            }
        });
    }

    private function applyGuestSearch(
        Builder $query,
        string $like,
    ): void {
        $query->where(function (Builder $query) use ($like): void {
            $query->where('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('contact_no', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    private function applyUserSearch(
        Builder $query,
        string $like,
    ): void {
        $query->where(function (Builder $query) use ($like): void {
            $query->where('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('username', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    private function paginateOrGet(
        Builder|QueryBuilder $query,
        int $perPage,
        string $pageName,
        bool $allRows,
    ): mixed {
        if ($allRows) {
            return $query->get();
        }

        return $query->paginate(
            $this->validPerPage($perPage),
            ['*'],
            $pageName,
        );
    }

    private function validPerPage(int $perPage): int
    {
        return in_array($perPage, [10, 25, 50, 100], true)
            ? $perPage
            : 25;
    }

    private function blockedFacilityIds(
        string $startDate,
        string $endDate,
    ): array {
        $bookingBlocked = DB::table('tbl_booking_details')
            ->join(
                'tbl_booking',
                'tbl_booking.booking_id',
                '=',
                'tbl_booking_details.booking_id',
            )
            ->whereNotNull('tbl_booking_details.facility_id')
            ->whereNotIn(
                'tbl_booking.status',
                ['Cancelled', 'Canceled', 'Checked-out'],
            )
            ->whereNotIn(
                'tbl_booking_details.status',
                [
                    'Cancelled',
                    'Canceled',
                    'Transferred',
                    'Checked-out',
                ],
            )
            ->where(
                'tbl_booking_details.check_in_date',
                '<=',
                $endDate,
            )
            ->where(
                'tbl_booking_details.check_out_date',
                '>=',
                $startDate,
            )
            ->pluck('tbl_booking_details.facility_id')
            ->all();

        $reservationBlocked = DB::table('tbl_reservation_details')
            ->join(
                'tbl_reservation',
                'tbl_reservation.reservation_id',
                '=',
                'tbl_reservation_details.reservation_id',
            )
            ->whereNotNull('tbl_reservation_details.facility_id')
            ->whereNotIn(
                'tbl_reservation.status',
                [
                    'Cancelled',
                    'Canceled',
                    'Expired',
                    'Converted',
                ],
            )
            ->where(
                'tbl_reservation_details.check_in_date',
                '<=',
                $endDate,
            )
            ->where(
                'tbl_reservation_details.check_out_date',
                '>=',
                $startDate,
            )
            ->pluck('tbl_reservation_details.facility_id')
            ->all();

        return array_values(
            array_unique(
                array_merge(
                    $bookingBlocked,
                    $reservationBlocked,
                )
            )
        );
    }
}

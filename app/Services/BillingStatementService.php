<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\FacilityPrice;
use App\Models\GuestFine;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BillingStatementService
{
    public function records(array $filters = []): Collection
    {
        $transactionType = strtolower((string) ($filters['transaction_type'] ?? 'all'));
        $records = collect();

        if ($transactionType === 'all' || $transactionType === 'booking') {
            $records = $records->merge($this->bookingRecords($filters));
        }

        if ($transactionType === 'all' || $transactionType === 'amenity_request') {
            $records = $records->merge($this->amenityRequestRecords($filters));
        }

        if ($transactionType === 'all' || $transactionType === 'fine') {
            $records = $records->merge($this->fineRecords($filters));
        }

        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        if ($search !== '') {
            $records = $records->filter(function (array $record) use ($search): bool {
                $haystack = strtolower(implode(' ', [
                    $record['reference_no'],
                    $record['booking_ref_no'],
                    $record['guest_name'],
                    $record['description'],
                    $record['transaction_type'],
                ]));

                return str_contains($haystack, $search);
            });
        }

        $paymentStatus = strtolower((string) ($filters['payment_status'] ?? 'all'));
        if ($paymentStatus !== 'all') {
            $records = $records->filter(function (array $record) use ($paymentStatus): bool {
                return strtolower($record['payment_status']) === $paymentStatus;
            });
        }

        return $records
            ->sortByDesc('date')
            ->values();
    }

    /**
     * Return a database-paginated billing ledger.
     *
     * The older records() method is intentionally retained because other
     * modules may still depend on its Collection return value.
     */
    public function paginatedRecords(
        array $filters = [],
        int $perPage = 10,
        string $sortField = 'date',
        string $sortDirection = 'desc',
        string $pageName = 'page',
    ): array {
        $query = $this->billingRecordsQuery($filters);

        $summary = [
            'count' => (clone $query)->count(),
            'total_amount' => round(
                (float) (clone $query)->sum('amount'),
                2,
            ),
            'total_due' => round(
                (float) (clone $query)->sum('amount_due'),
                2,
            ),
            'paid_count' => (clone $query)
                ->where('payment_status', 'Paid')
                ->count(),
            'unpaid_count' => (clone $query)
                ->where('payment_status', 'Unpaid')
                ->count(),
        ];

        $allowedSorts = [
            'date',
            'transaction_type',
            'guest_name',
            'amount',
            'amount_due',
            'payment_status',
        ];

        $sortField = in_array($sortField, $allowedSorts, true)
            ? $sortField
            : 'date';

        $sortDirection = $sortDirection === 'asc'
            ? 'asc'
            : 'desc';

        $perPage = in_array($perPage, [10, 25, 50, 100], true)
            ? $perPage
            : 10;

        $rows = $query
            ->orderBy($sortField, $sortDirection)
            ->orderBy('reference_no', 'desc')
            ->paginate(
                $perPage,
                ['*'],
                $pageName,
            );

        $rows->through(
            fn (object $row): array => (array) $row,
        );

        return [
            'rows' => $rows,
            ...$summary,
        ];
    }

    public function statementForBooking(int $bookingId): array
    {
        $booking = Booking::query()
            ->with([
                'guest.address',
                'user',
                'reservation',
                'entranceSlip',
                'details.facility.facilityType',
                'details.discount',
                'extraGuests',
                'amenityRequests.details.amenity.amenityName',
                'amenityRequests.details.facility',
                'guestFines.fine.amenity.amenityName',
                'guestFines.fine.damageType',
                'guestFines.facility',
                'guestFines.reportedBy',
                'payments.modeOfPayment',
                'payments.user',
            ])
            ->findOrFail($bookingId);

        $verifiedPayments = $booking->payments->filter(function ($payment): bool {
            return strtolower((string) $payment->payment_status) === 'verified';
        });

        $facilityLines = $booking->details->map(function ($detail): array {
            return [
                'facility' => $detail->facility?->facility_name ?? 'Facility unavailable',
                'facility_type' => $detail->facility?->facilityType?->facility_type ?? 'N/A',
                'rate_type' => (string) $detail->rate_type,
                'check_in_date' => optional($detail->check_in_date)->toDateString(),
                'check_out_date' => optional($detail->check_out_date)->toDateString(),
                'status' => (string) $detail->status,
                'base_price' => $this->moneyOrFallback($detail->base_price, $this->currentFacilityRate((int) $detail->facility_id, (string) $detail->rate_type)),
                'discount_amount' => round((float) ($detail->discount_amount ?? 0), 2),
                'extra_guest_fee' => round((float) ($detail->extra_guest_fee ?? 0), 2),
                'line_total' => $this->moneyOrFallback($detail->line_total, null),
                'has_snapshot' => $detail->line_total !== null,
            ];
        })->values();

        $amenityLines = $booking->amenityRequests
            ->filter(function ($request): bool {
                return (string) $request->amenity_request_status !== 'Cancelled';
            })
            ->flatMap(function ($request) {
                return $request->details->map(function ($detail) use ($request): array {
                    $unitPrice = $this->moneyOrFallback($detail->unit_price, (float) ($detail->amenity?->amenity_price ?? 0));
                    $quantity = (int) $detail->amenity_quantity;

                    return [
                        'request_id' => (int) $request->amenity_request_id,
                        'request_status' => (string) $request->amenity_request_status,
                        'date_created' => optional($request->date_created)->toDateString(),
                        'amenity' => $detail->amenity?->amenityName?->amenity_name ?? 'Amenity unavailable',
                        'facility' => $detail->facility?->facility_name ?? 'Facility unavailable',
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => $this->moneyOrFallback($detail->line_total, round($unitPrice * $quantity, 2)),
                        'has_snapshot' => $detail->line_total !== null,
                    ];
                });
            })
            ->values();

        $fineLines = $booking->guestFines->map(function ($guestFine): array {
            $fine = $guestFine->fine;
            $description = $fine?->fine_type === 'Amenity'
                ? trim(($fine?->amenity?->amenityName?->amenity_name ?? 'Amenity') . ' - ' . ($fine?->damageType?->damage_type ?? 'Damage'))
                : ($fine?->situational_fine ?? 'Situational Fine');

            return [
                'guest_fine_id' => (int) $guestFine->guest_fine_id,
                'description' => $description,
                'facility' => $guestFine->facility?->facility_name ?? 'N/A',
                'quantity' => (int) $guestFine->quantity,
                'total_charge' => round((float) $guestFine->total_charge, 2),
                'date_checked' => optional($guestFine->date_checked)->toDateString(),
                'reported_by' => $guestFine->reportedBy?->full_name ?? $guestFine->reportedBy?->username ?? 'N/A',
            ];
        })->values();

        $paymentLines = $verifiedPayments->map(function ($payment): array {
            return [
                'payment_ref_no' => (string) $payment->p_ref_no,
                'mode' => $payment->modeOfPayment?->mode_of_payment ?? 'N/A',
                'reference_number' => $payment->reference_number,
                'amount_paid' => round((float) $payment->amount_paid, 2),
                'date_paid' => optional($payment->date_paid)->toDateString(),
                'received_by' => $payment->user?->full_name ?? $payment->user?->username ?? 'N/A',
            ];
        })->values();

        return [
            'booking' => $booking,
            'guest_name' => $booking->guest?->full_name ?? 'Guest unavailable',
            'guest_contact' => $booking->guest?->contact_no ?? 'N/A',
            'guest_email' => $booking->guest?->email ?? 'N/A',
            'facility_lines' => $facilityLines,
            'amenity_lines' => $amenityLines,
            'fine_lines' => $fineLines,
            'payment_lines' => $paymentLines,
            'total_price' => round((float) $booking->total_price, 2),
            'total_paid' => round((float) $verifiedPayments->sum('amount_paid'), 2),
            'amount_due' => round((float) $booking->amount_due, 2),
            'payment_status' => round((float) $booking->amount_due, 2) <= 0 ? 'Paid' : 'Unpaid',
            'generated_at' => Carbon::now()->format('Y-m-d h:i A'),
        ];
    }

    private function bookingRecords(array $filters): Collection
    {
        return Booking::query()
            ->with(['guest', 'payments'])
            ->when($this->from($filters), function ($query, string $from): void {
                $query->whereDate('booking_date', '>=', $from);
            })
            ->when($this->to($filters), function ($query, string $to): void {
                $query->whereDate('booking_date', '<=', $to);
            })
            ->get()
            ->map(function (Booking $booking): array {
                return [
                    'transaction_type' => 'Booking',
                    'reference_no' => (string) $booking->b_ref_no,
                    'booking_ref_no' => (string) $booking->b_ref_no,
                    'booking_id' => (int) $booking->booking_id,
                    'guest_name' => $booking->guest?->full_name ?? 'Guest unavailable',
                    'date' => optional($booking->booking_date)->toDateString(),
                    'description' => 'Facility booking',
                    'amount' => round((float) $booking->total_price, 2),
                    'amount_due' => round((float) $booking->amount_due, 2),
                    'payment_status' => round((float) $booking->amount_due, 2) <= 0 ? 'Paid' : 'Unpaid',
                ];
            });
    }

    private function amenityRequestRecords(array $filters): Collection
    {
        return AmenityRequest::query()
            ->with(['booking.guest', 'details.amenity.amenityName'])
            ->where('amenity_request_status', '!=', 'Cancelled')
            ->when($this->from($filters), function ($query, string $from): void {
                $query->whereDate('date_created', '>=', $from);
            })
            ->when($this->to($filters), function ($query, string $to): void {
                $query->whereDate('date_created', '<=', $to);
            })
            ->get()
            ->map(function (AmenityRequest $request): array {
                $names = $request->details->map(function ($detail): string {
                    return $detail->amenity?->amenityName?->amenity_name ?? 'Amenity';
                })->unique()->implode(', ');

                return [
                    'transaction_type' => 'Amenity Request',
                    'reference_no' => 'AR-' . $request->amenity_request_id,
                    'booking_ref_no' => (string) ($request->booking?->b_ref_no ?? 'N/A'),
                    'booking_id' => (int) ($request->booking_id ?? 0),
                    'guest_name' => $request->booking?->guest?->full_name ?? 'Guest unavailable',
                    'date' => optional($request->date_created)->toDateString(),
                    'description' => $names !== '' ? $names : 'Amenity request',
                    'amount' => round((float) $request->total_price, 2),
                    'amount_due' => $request->amenity_request_status === 'Awaiting Payment' ? round((float) $request->total_price, 2) : 0.00,
                    'payment_status' => $request->amenity_request_status === 'Awaiting Payment' ? 'Unpaid' : 'Paid',
                ];
            });
    }

    private function fineRecords(array $filters): Collection
    {
        return GuestFine::query()
            ->with(['booking.guest', 'fine.amenity.amenityName', 'fine.damageType'])
            ->when($this->from($filters), function ($query, string $from): void {
                $query->whereDate('date_checked', '>=', $from);
            })
            ->when($this->to($filters), function ($query, string $to): void {
                $query->whereDate('date_checked', '<=', $to);
            })
            ->get()
            ->map(function (GuestFine $guestFine): array {
                $fine = $guestFine->fine;
                $description = $fine?->fine_type === 'Amenity'
                    ? trim(($fine?->amenity?->amenityName?->amenity_name ?? 'Amenity') . ' - ' . ($fine?->damageType?->damage_type ?? 'Damage'))
                    : ($fine?->situational_fine ?? 'Fine');

                return [
                    'transaction_type' => 'Fine',
                    'reference_no' => 'GF-' . $guestFine->guest_fine_id,
                    'booking_ref_no' => (string) ($guestFine->booking?->b_ref_no ?? 'N/A'),
                    'booking_id' => (int) ($guestFine->booking_id ?? 0),
                    'guest_name' => $guestFine->booking?->guest?->full_name ?? 'Guest unavailable',
                    'date' => optional($guestFine->date_checked)->toDateString(),
                    'description' => $description,
                    'amount' => round((float) $guestFine->total_charge, 2),
                    'amount_due' => round((float) ($guestFine->booking?->amount_due ?? 0), 2) > 0 ? round((float) $guestFine->total_charge, 2) : 0.00,
                    'payment_status' => round((float) ($guestFine->booking?->amount_due ?? 0), 2) <= 0 ? 'Paid' : 'Unpaid',
                ];
            });
    }

    private function billingRecordsQuery(array $filters): QueryBuilder
    {
        $transactionType = strtolower(
            (string) ($filters['transaction_type'] ?? 'all')
        );

        $queries = [];

        if (
            $transactionType === 'all'
            || $transactionType === 'booking'
        ) {
            $queries[] = $this->bookingRecordQuery($filters);
        }

        if (
            $transactionType === 'all'
            || $transactionType === 'amenity_request'
        ) {
            $queries[] = $this->amenityRequestRecordQuery($filters);
        }

        if (
            $transactionType === 'all'
            || $transactionType === 'fine'
        ) {
            $queries[] = $this->fineRecordQuery($filters);
        }

        // Validation in the UI restricts this to known values, but retaining
        // a fallback keeps the service safe for direct callers.
        if ($queries === []) {
            $queries[] = $this->bookingRecordQuery($filters);
        }

        $union = array_shift($queries);

        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        $ledger = DB::query()
            ->fromSub($union, 'billing_records');

        $search = trim(
            (string) ($filters['search'] ?? '')
        );

        if ($search !== '') {
            $like = '%'.$search.'%';

            $ledger->where(function (QueryBuilder $query) use ($like): void {
                $query->where('reference_no', 'like', $like)
                    ->orWhere('booking_ref_no', 'like', $like)
                    ->orWhere('guest_name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('transaction_type', 'like', $like);
            });
        }

        $paymentStatus = strtolower(
            (string) ($filters['payment_status'] ?? 'all')
        );

        if ($paymentStatus === 'paid') {
            $ledger->where('payment_status', 'Paid');
        } elseif ($paymentStatus === 'unpaid') {
            $ledger->where('payment_status', 'Unpaid');
        }

        return $ledger;
    }

    private function bookingRecordQuery(array $filters): QueryBuilder
    {
        $guestName = $this->guestNameExpression('billing_guest');

        return DB::table('tbl_booking as billing_booking')
            ->join(
                'tbl_guest as billing_guest',
                'billing_guest.guest_id',
                '=',
                'billing_booking.guest_id',
            )
            ->when(
                $this->from($filters),
                fn (QueryBuilder $query, string $from) =>
                    $query->whereDate(
                        'billing_booking.booking_date',
                        '>=',
                        $from,
                    ),
            )
            ->when(
                $this->to($filters),
                fn (QueryBuilder $query, string $to) =>
                    $query->whereDate(
                        'billing_booking.booking_date',
                        '<=',
                        $to,
                    ),
            )
            ->selectRaw("'Booking' as transaction_type")
            ->selectRaw(
                'billing_booking.b_ref_no as reference_no'
            )
            ->selectRaw(
                'billing_booking.b_ref_no as booking_ref_no'
            )
            ->selectRaw(
                'billing_booking.booking_id as booking_id'
            )
            ->selectRaw($guestName.' as guest_name')
            ->selectRaw(
                'billing_booking.booking_date as date'
            )
            ->selectRaw("'Facility booking' as description")
            ->selectRaw(
                'billing_booking.total_price as amount'
            )
            ->selectRaw(
                'billing_booking.amount_due as amount_due'
            )
            ->selectRaw(
                "CASE
                    WHEN billing_booking.amount_due <= 0
                        THEN 'Paid'
                    ELSE 'Unpaid'
                END as payment_status"
            );
    }

    private function amenityRequestRecordQuery(
        array $filters,
    ): QueryBuilder {
        $guestName = $this->guestNameExpression(
            'amenity_guest',
        );

        $reference = $this->prefixedIdExpression(
            'AR-',
            'billing_amenity_request.amenity_request_id',
        );

        return DB::table(
            'tbl_amenity_request as billing_amenity_request'
        )
            ->join(
                'tbl_booking as amenity_booking',
                'amenity_booking.booking_id',
                '=',
                'billing_amenity_request.booking_id',
            )
            ->join(
                'tbl_guest as amenity_guest',
                'amenity_guest.guest_id',
                '=',
                'amenity_booking.guest_id',
            )
            ->where(
                'billing_amenity_request.amenity_request_status',
                '!=',
                'Cancelled',
            )
            ->when(
                $this->from($filters),
                fn (QueryBuilder $query, string $from) =>
                    $query->whereDate(
                        'billing_amenity_request.date_created',
                        '>=',
                        $from,
                    ),
            )
            ->when(
                $this->to($filters),
                fn (QueryBuilder $query, string $to) =>
                    $query->whereDate(
                        'billing_amenity_request.date_created',
                        '<=',
                        $to,
                    ),
            )
            ->selectRaw(
                "'Amenity Request' as transaction_type"
            )
            ->selectRaw($reference.' as reference_no')
            ->selectRaw(
                'amenity_booking.b_ref_no as booking_ref_no'
            )
            ->selectRaw(
                'amenity_booking.booking_id as booking_id'
            )
            ->selectRaw($guestName.' as guest_name')
            ->selectRaw(
                'billing_amenity_request.date_created as date'
            )
            ->selectRaw(
                "'Requested rentable amenities' as description"
            )
            ->selectRaw(
                'billing_amenity_request.total_price as amount'
            )
            ->selectRaw(
                "CASE
                    WHEN billing_amenity_request.amenity_request_status
                        = 'Awaiting Payment'
                    THEN billing_amenity_request.total_price
                    ELSE 0
                END as amount_due"
            )
            ->selectRaw(
                "CASE
                    WHEN billing_amenity_request.amenity_request_status
                        = 'Awaiting Payment'
                    THEN 'Unpaid'
                    ELSE 'Paid'
                END as payment_status"
            );
    }

    private function fineRecordQuery(array $filters): QueryBuilder
    {
        $guestName = $this->guestNameExpression(
            'fine_guest',
        );

        $reference = $this->prefixedIdExpression(
            'GF-',
            'billing_guest_fine.guest_fine_id',
        );

        $amenityDescription = $this->fineDescriptionExpression();

        return DB::table(
            'tbl_guest_fine as billing_guest_fine'
        )
            ->join(
                'tbl_booking as fine_booking',
                'fine_booking.booking_id',
                '=',
                'billing_guest_fine.booking_id',
            )
            ->join(
                'tbl_guest as fine_guest',
                'fine_guest.guest_id',
                '=',
                'fine_booking.guest_id',
            )
            ->join(
                'tbl_fine as billing_fine',
                'billing_fine.fine_id',
                '=',
                'billing_guest_fine.fine_id',
            )
            ->leftJoin(
                'tbl_amenity as fine_amenity',
                'fine_amenity.amenity_id',
                '=',
                'billing_fine.amenity_id',
            )
            ->leftJoin(
                'tbl_amenity_name as fine_amenity_name',
                'fine_amenity_name.amenity_name_id',
                '=',
                'fine_amenity.amenity_name_id',
            )
            ->leftJoin(
                'tbl_damage_type as fine_damage_type',
                'fine_damage_type.damage_type_id',
                '=',
                'billing_fine.damage_type_id',
            )
            ->when(
                $this->from($filters),
                fn (QueryBuilder $query, string $from) =>
                    $query->whereDate(
                        'billing_guest_fine.date_checked',
                        '>=',
                        $from,
                    ),
            )
            ->when(
                $this->to($filters),
                fn (QueryBuilder $query, string $to) =>
                    $query->whereDate(
                        'billing_guest_fine.date_checked',
                        '<=',
                        $to,
                    ),
            )
            ->selectRaw("'Fine' as transaction_type")
            ->selectRaw($reference.' as reference_no')
            ->selectRaw(
                'fine_booking.b_ref_no as booking_ref_no'
            )
            ->selectRaw(
                'fine_booking.booking_id as booking_id'
            )
            ->selectRaw($guestName.' as guest_name')
            ->selectRaw(
                'billing_guest_fine.date_checked as date'
            )
            ->selectRaw($amenityDescription.' as description')
            ->selectRaw(
                'billing_guest_fine.total_charge as amount'
            )
            ->selectRaw(
                "CASE
                    WHEN fine_booking.amount_due > 0
                    THEN billing_guest_fine.total_charge
                    ELSE 0
                END as amount_due"
            )
            ->selectRaw(
                "CASE
                    WHEN fine_booking.amount_due <= 0
                    THEN 'Paid'
                    ELSE 'Unpaid'
                END as payment_status"
            );
    }

    private function guestNameExpression(string $alias): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite', 'pgsql' =>
                "TRIM(
                    COALESCE({$alias}.first_name, '')
                    || ' '
                    || COALESCE({$alias}.middle_name, '')
                    || ' '
                    || COALESCE({$alias}.last_name, '')
                )",
            'sqlsrv' =>
                "LTRIM(RTRIM(
                    CONCAT(
                        COALESCE({$alias}.first_name, ''),
                        ' ',
                        COALESCE({$alias}.middle_name, ''),
                        ' ',
                        COALESCE({$alias}.last_name, '')
                    )
                ))",
            default =>
                "TRIM(
                    CONCAT_WS(
                        ' ',
                        {$alias}.first_name,
                        NULLIF({$alias}.middle_name, ''),
                        {$alias}.last_name
                    )
                )",
        };
    }

    private function prefixedIdExpression(
        string $prefix,
        string $column,
    ): string {
        $escapedPrefix = str_replace(
            "'",
            "''",
            $prefix,
        );

        return match (DB::connection()->getDriverName()) {
            'sqlite', 'pgsql' =>
                "'{$escapedPrefix}' || CAST({$column} AS TEXT)",
            'sqlsrv' =>
                "CONCAT('{$escapedPrefix}', CAST({$column} AS VARCHAR(30)))",
            default =>
                "CONCAT('{$escapedPrefix}', {$column})",
        };
    }

    private function fineDescriptionExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite', 'pgsql' =>
                "CASE
                    WHEN billing_fine.fine_type
                        IN ('Amenity', 'Amenity Fine')
                    THEN TRIM(
                        COALESCE(
                            fine_amenity_name.amenity_name,
                            'Amenity'
                        )
                        || ' - '
                        || COALESCE(
                            fine_damage_type.damage_type,
                            'Damage'
                        )
                    )
                    ELSE COALESCE(
                        billing_fine.situational_fine,
                        'Fine'
                    )
                END",
            default =>
                "CASE
                    WHEN billing_fine.fine_type
                        IN ('Amenity', 'Amenity Fine')
                    THEN TRIM(
                        CONCAT(
                            COALESCE(
                                fine_amenity_name.amenity_name,
                                'Amenity'
                            ),
                            ' - ',
                            COALESCE(
                                fine_damage_type.damage_type,
                                'Damage'
                            )
                        )
                    )
                    ELSE COALESCE(
                        billing_fine.situational_fine,
                        'Fine'
                    )
                END",
        };
    }

    private function from(array $filters): ?string
    {
        $value = trim((string) ($filters['from_date'] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function to(array $filters): ?string
    {
        $value = trim((string) ($filters['to_date'] ?? ''));

        return $value !== '' ? $value : null;
    }

    private function moneyOrFallback(mixed $value, ?float $fallback): float
    {
        if ($value !== null) {
            return round((float) $value, 2);
        }

        return round((float) ($fallback ?? 0), 2);
    }

    private function currentFacilityRate(int $facilityId, string $rateType): ?float
    {
        if ($facilityId < 1 || $rateType === '') {
            return null;
        }

        $price = FacilityPrice::query()
            ->where('facility_id', $facilityId)
            ->where('rate_type', $rateType)
            ->value('facility_price');

        return $price !== null ? (float) $price : null;
    }
}

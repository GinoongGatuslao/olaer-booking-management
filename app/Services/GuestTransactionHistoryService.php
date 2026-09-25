<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\TransactionAdjustment;
use App\Models\TransactionCredit;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GuestTransactionHistoryService
{
    public function __construct(
        private readonly BillingStatementService $billing,
        private readonly TransactionLedgerService $ledger,
    ) {}

    /** @param array<string, mixed> $filters */
    public function paginated(
        array $filters,
        int $perPage = 10,
        string $sortField = 'date',
        string $sortDirection = 'desc',
    ): LengthAwarePaginator {
        $reservations = DB::table('tbl_reservation as r')
            ->join('tbl_guest as g', 'g.guest_id', '=', 'r.guest_id')
            ->selectRaw("'reservation' as transaction_type")
            ->addSelect([
                'r.reservation_id as transaction_id',
                'r.r_ref_no as reference_no',
                'g.guest_id',
                'g.first_name',
                'g.last_name',
                'g.email',
                'g.contact_no',
                'r.reservation_date as transaction_date',
                'r.total_price',
                'r.amount_due',
                'r.status',
                'r.created_at',
            ]);

        $bookings = DB::table('tbl_booking as b')
            ->join('tbl_guest as g', 'g.guest_id', '=', 'b.guest_id')
            ->selectRaw("'booking' as transaction_type")
            ->addSelect([
                'b.booking_id as transaction_id',
                'b.b_ref_no as reference_no',
                'g.guest_id',
                'g.first_name',
                'g.last_name',
                'g.email',
                'g.contact_no',
                'b.booking_date as transaction_date',
                'b.total_price',
                'b.amount_due',
                'b.status',
                'b.created_at',
            ]);

        $query = DB::query()->fromSub(
            $reservations->unionAll($bookings),
            'history',
        );

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($query) use ($like): void {
                $query->where('reference_no', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('contact_no', 'like', $like)
                    ->orWhere('status', 'like', $like);
            });
        }

        if (filled($filters['from_date'] ?? null)) {
            $query->whereDate('transaction_date', '>=', $filters['from_date']);
        }

        if (filled($filters['to_date'] ?? null)) {
            $query->whereDate('transaction_date', '<=', $filters['to_date']);
        }

        $type = strtolower((string) ($filters['transaction_type'] ?? 'all'));
        if (in_array($type, ['reservation', 'booking'], true)) {
            $query->where('transaction_type', $type);
        }

        $paymentStatus = strtolower((string) ($filters['payment_status'] ?? 'all'));
        if ($paymentStatus === 'paid') {
            $query->where('amount_due', '<=', 0);
        } elseif ($paymentStatus === 'unpaid') {
            $query->where('amount_due', '>', 0);
        }

        $sortColumn = match ($sortField) {
            'transaction_type' => 'transaction_type',
            'guest_name' => 'last_name',
            'amount' => 'total_price',
            'amount_due' => 'amount_due',
            'payment_status' => 'amount_due',
            default => 'transaction_date',
        };
        $direction = $sortDirection === 'asc' ? 'asc' : 'desc';

        return $query
            ->orderBy($sortColumn, $direction)
            ->orderBy('transaction_id', 'desc')
            ->paginate(in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10);
    }

    /**
     * @return array<string, mixed>
     */
    public function statement(string $type, int $id): array
    {
        return match (strtolower($type)) {
            'booking' => $this->bookingStatement($id),
            'reservation' => $this->reservationStatement($id),
            default => throw new InvalidArgumentException('Unsupported transaction type.'),
        };
    }

    /** @return array<string, mixed> */
    private function bookingStatement(int $bookingId): array
    {
        $statement = $this->billing->statementForBooking($bookingId);
        $booking = $statement['booking'];
        $ledger = $this->ledger->summaryForBooking($bookingId);

        $credits = TransactionCredit::query()
            ->with('allocations')
            ->where('booking_id', $bookingId)
            ->orderBy('transaction_credit_id')
            ->get();

        $adjustments = TransactionAdjustment::query()
            ->where('booking_id', $bookingId)
            ->orderBy('created_at')
            ->get();

        $entranceSlip = $booking->entranceSlip()
            ->with(['details.entranceFee', 'payments.modeOfPayment'])
            ->first();

        return [
            ...$statement,
            'transaction_type' => 'Booking',
            'transaction_id' => $bookingId,
            'reference_no' => $booking->b_ref_no,
            'transaction_status' => $booking->status,
            'ledger' => $ledger,
            'credits' => $credits,
            'adjustments' => $adjustments,
            'entrance_slip' => $entranceSlip,
        ];
    }

    /** @return array<string, mixed> */
    private function reservationStatement(int $reservationId): array
    {
        $reservation = Reservation::query()
            ->with([
                'guest.address',
                'details.facility.facilityType',
                'details.discount',
                'details.roomOccupants',
                'payments.modeOfPayment',
                'payments.verifier',
                'booking',
            ])
            ->findOrFail($reservationId);

        $ledger = $this->ledger->summaryForReservation($reservation);
        $credits = TransactionCredit::query()
            ->with('allocations')
            ->where('reservation_id', $reservationId)
            ->orderBy('transaction_credit_id')
            ->get();
        $adjustments = TransactionAdjustment::query()
            ->where('reservation_id', $reservationId)
            ->orderBy('created_at')
            ->get();

        $facilityLines = $reservation->details->map(fn ($detail): array => [
            'facility' => $detail->facility?->facility_number
                ? $detail->facility->facility_number.' — '.$detail->facility->facility_name
                : ($detail->facility?->facility_name ?? 'Facility'),
            'facility_type' => $detail->facility?->facilityType?->facility_type ?? 'Facility',
            'rate_type' => $detail->rate_type,
            'check_in_date' => $detail->check_in_date?->toDateString(),
            'check_out_date' => $detail->check_out_date?->toDateString(),
            'status' => $detail->status ?? 'Active',
            'base_price' => (string) ($detail->base_price ?? '0.00'),
            'discount_amount' => (string) ($detail->discount_amount ?? '0.00'),
            'extra_guest_fee' => (string) ($detail->extra_guest_fee ?? '0.00'),
            'line_total' => (string) ($detail->line_total ?? '0.00'),
            'occupants' => $detail->roomOccupants,
        ])->values();

        $paymentLines = $reservation->payments->map(fn ($payment): array => [
            'payment_ref_no' => $payment->p_ref_no,
            'mode' => $payment->modeOfPayment?->mode_of_payment ?? 'Unknown',
            'reference_number' => $payment->reference_number,
            'date_paid' => optional($payment->date_paid)->toDateString(),
            'amount_paid' => (string) $payment->amount_paid,
            'status' => $payment->payment_status,
            'verified_by' => $payment->verifier?->full_name,
        ])->values();

        $guest = $reservation->guest;

        return [
            'transaction_type' => 'Reservation',
            'transaction_id' => $reservationId,
            'reference_no' => $reservation->r_ref_no,
            'transaction_status' => $reservation->status,
            'generated_at' => now()->format('M d, Y h:i A'),
            'reservation' => $reservation,
            'booking' => $reservation->booking,
            'guest_name' => trim(($guest?->first_name ?? '').' '.($guest?->last_name ?? '')),
            'guest_contact' => $guest?->contact_no,
            'guest_email' => $guest?->email,
            'facility_lines' => $facilityLines,
            'amenity_lines' => collect(),
            'fine_lines' => collect(),
            'payment_lines' => $paymentLines,
            'ledger' => $ledger,
            'credits' => $credits,
            'adjustments' => $adjustments,
            'entrance_slip' => null,
            'total_price' => $ledger['total'],
            'total_paid' => $ledger['verified_payments'],
            'amount_due' => $ledger['balance'],
            'payment_status' => $ledger['balance'] === '0.00' ? 'Paid' : 'Outstanding',
        ];
    }
}

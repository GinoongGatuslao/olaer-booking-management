<?php

namespace App\Services;

use App\Models\BookingDetail;
use App\Models\EntranceSlip;
use App\Models\FacilityInspectionRequest;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Support\Collection;

class CashierDashboardService
{
    public function overview(int $cashierUserId): array
    {
        return [
            'my_revenue_today' => (float) Payment::query()
                ->where('payment_status', 'Verified')
                ->whereDate('date_paid', today())
                ->where(function ($query) use ($cashierUserId): void {
                    $query->where('user_id', $cashierUserId)
                        ->orWhere('verified_by_user_id', $cashierUserId);
                })
                ->sum('amount_paid'),

            'unpaid_entrance_slips' => EntranceSlip::query()
                ->where('status', 'Unpaid')
                ->count(),

            'active_reservations' => Reservation::query()
                ->where('status', 'Active')
                ->count(),

            'pending_gcash' => Payment::query()
                ->where('payment_status', 'Pending')
                ->whereNotNull('booking_id')
                ->whereNotNull('proof_of_payment_path')
                ->whereHas('modeOfPayment', function ($query): void {
                    $query->whereRaw('LOWER(mode_of_payment) = ?', ['gcash']);
                })
                ->count(),

            'check_ins_today' => BookingDetail::query()
                ->where('status', 'Booked')
                ->whereDate('check_in_date', today())
                ->whereHas('booking', function ($query): void {
                    $query->where('amount_due', '<=', 0)
                        ->whereNotIn('status', ['Cancelled', 'Payment Rejected', 'Checked-out']);
                })
                ->count(),

            'checked_in_facilities' => BookingDetail::query()
                ->where('status', 'Checked-in')
                ->count(),

            'inspection_requests_open' => FacilityInspectionRequest::query()
                ->whereIn('status', ['Pending', 'In Progress'])
                ->count(),

            'ready_for_checkout' => FacilityInspectionRequest::query()
                ->where('status', 'Completed')
                ->whereHas('bookingDetail', function ($query): void {
                    $query->where('status', 'Checked-in');
                })
                ->whereHas('booking', function ($query): void {
                    $query->where('amount_due', '<=', 0)
                        ->whereNotIn('status', ['Cancelled', 'Checked-out']);
                })
                ->count(),
        ];
    }

    public function upcomingCheckIns(int $limit = 8): Collection
    {
        return BookingDetail::query()
            ->with([
                'booking.guest',
                'facility.facilityType',
            ])
            ->where('status', 'Booked')
            ->whereBetween('check_in_date', [
                today()->toDateString(),
                today()->addDay()->toDateString(),
            ])
            ->whereHas('booking', function ($query): void {
                $query->where('amount_due', '<=', 0)
                    ->whereNotIn('status', ['Cancelled', 'Payment Rejected', 'Checked-out']);
            })
            ->orderBy('check_in_date')
            ->orderBy('check_in_time')
            ->limit($limit)
            ->get();
    }

    /**
     * Returns checked-in facilities with their latest cashier-sent inspection request.
     */
    public function checkoutQueue(int $limit = 8): Collection
    {
        $details = BookingDetail::query()
            ->with([
                'booking.guest',
                'facility.facilityType',
            ])
            ->where('status', 'Checked-in')
            ->orderBy('check_out_date')
            ->limit($limit)
            ->get();

        $requests = FacilityInspectionRequest::query()
            ->whereIn('booking_details_id', $details->pluck('booking_details_id'))
            ->latest('facility_inspection_request_id')
            ->get()
            ->unique('booking_details_id')
            ->keyBy('booking_details_id');

        return $details->map(function (BookingDetail $detail) use ($requests): array {
            $request = $requests->get($detail->booking_details_id);
            $amountDue = (float) ($detail->booking?->amount_due ?? 0);

            if ($request === null) {
                $state = 'Inspection not requested';
                $badgeColor = 'amber';
            } elseif (in_array((string) $request->status, ['Pending', 'In Progress'], true)) {
                $state = 'Inspection pending';
                $badgeColor = 'blue';
            } elseif ((string) $request->status === 'Completed' && $amountDue > 0) {
                $state = 'Payment required';
                $badgeColor = 'red';
            } elseif ((string) $request->status === 'Completed') {
                $state = 'Ready for check-out';
                $badgeColor = 'green';
            } else {
                $state = (string) $request->status;
                $badgeColor = 'zinc';
            }

            return [
                'detail' => $detail,
                'inspection_request' => $request,
                'state' => $state,
                'badge_color' => $badgeColor,
                'amount_due' => $amountDue,
            ];
        });
    }

    public function pendingGcashPayments(int $limit = 6): Collection
    {
        return Payment::query()
            ->with([
                'booking.guest',
                'reservation.guest',
                'modeOfPayment',
            ])
            ->where('payment_status', 'Pending')
            ->whereNotNull('booking_id')
            ->whereNotNull('proof_of_payment_path')
            ->whereHas('modeOfPayment', function ($query): void {
                $query->whereRaw('LOWER(mode_of_payment) = ?', ['gcash']);
            })
            ->latest('payment_id')
            ->limit($limit)
            ->get();
    }

    public function recentPayments(int $cashierUserId, int $limit = 8): Collection
    {
        return Payment::query()
            ->with([
                'modeOfPayment',
                'booking.guest',
                'reservation.guest',
                'entranceSlip',
            ])
            ->where(function ($query) use ($cashierUserId): void {
                $query->where('user_id', $cashierUserId)
                    ->orWhere('verified_by_user_id', $cashierUserId);
            })
            ->latest('payment_id')
            ->limit($limit)
            ->get();
    }

    public function paymentTarget(Payment $payment): string
    {
        if ($payment->booking !== null) {
            return 'Booking '.$payment->booking->b_ref_no;
        }

        if ($payment->reservation !== null) {
            return 'Reservation '.$payment->reservation->r_ref_no;
        }

        if ($payment->entranceSlip !== null) {
            return 'Entrance Slip #'.$payment->entrance_slip_id;
        }

        return 'Unlinked payment';
    }

    public function paymentGuest(Payment $payment): string
    {
        return $payment->booking?->guest?->full_name
            ?? $payment->reservation?->guest?->full_name
            ?? 'Walk-in guest';
    }
}

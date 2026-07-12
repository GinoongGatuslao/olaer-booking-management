<?php

namespace App\Services;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\EntranceSlip;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AdminDashboardService
{
    public function overview(): array
    {
        $today = today();

        return [
            'today_revenue' => (float) Payment::query()
                ->where('payment_status', 'Verified')
                ->whereDate('date_paid', $today)
                ->sum('amount_paid'),

            'month_revenue' => (float) Payment::query()
                ->where('payment_status', 'Verified')
                ->whereYear('date_paid', $today->year)
                ->whereMonth('date_paid', $today->month)
                ->sum('amount_paid'),

            'occupied_facilities' => Facility::query()
                ->where('facility_status', 'Occupied')
                ->count(),

            'pending_gcash' => Payment::query()
                ->where('payment_status', 'Pending')
                ->count(),

            'active_staff' => User::query()
                ->where('status', 'Active')
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', now()->subMinutes(5))
                ->count(),

            'total_amenities' => Amenity::query()->count(),
        ];
    }

    public function revenueTrend(int $days = 7): array
    {
        $start = today()->subDays($days - 1);

        $totals = Payment::query()
            ->selectRaw('date_paid, SUM(amount_paid) AS total')
            ->where('payment_status', 'Verified')
            ->whereBetween('date_paid', [$start->toDateString(), today()->toDateString()])
            ->groupBy('date_paid')
            ->pluck('total', 'date_paid');

        return collect(range(0, $days - 1))
            ->map(function (int $offset) use ($start, $totals): array {
                $date = $start->copy()->addDays($offset);
                $key = $date->toDateString();

                return [
                    'date' => $key,
                    'label' => $date->format('d/m'),
                    'total' => (float) ($totals[$key] ?? 0),
                ];
            })
            ->all();
    }

    public function facilitiesInUse(int $limit = 8): Collection
    {
        return BookingDetail::query()
            ->with([
                'facility.facilityType',
                'booking.guest',
            ])
            ->where('status', 'Checked-in')
            ->whereNotNull('facility_id')
            ->latest('booking_details_id')
            ->limit($limit)
            ->get();
    }

    public function recentlyActiveStaff(int $limit = 8): Collection
    {
        return User::query()
            ->with('role')
            ->where('status', 'Active')
            ->whereNotNull('last_seen_at')
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Shows meaningful staff/guest activity from actual transaction records.
     * This avoids pretending that page views are business actions.
     */
    public function recentOperations(int $limit = 12): Collection
    {
        $payments = Payment::query()
            ->with(['user.role', 'verifier.role'])
            ->latest('payment_id')
            ->limit(6)
            ->get()
            ->map(function (Payment $payment): array {
                $actor = $payment->user ?? $payment->verifier;
                $verb = $payment->payment_status === 'Verified' ? 'Recorded payment' : 'Submitted payment';

                return [
                    'type' => 'Payment',
                    'description' => sprintf(
                        '%s %s for ₱%s',
                        $verb,
                        $payment->p_ref_no,
                        number_format((float) $payment->amount_paid, 2)
                    ),
                    'actor' => $actor?->full_name ?? 'Guest',
                    'role' => $actor?->role?->role_name ?? 'Guest',
                    'occurred_at' => $payment->created_at ?? Carbon::parse($payment->date_paid),
                ];
            });

        $bookings = Booking::query()
            ->with(['user.role', 'guest'])
            ->latest('booking_id')
            ->limit(5)
            ->get()
            ->map(fn (Booking $booking): array => [
                'type' => 'Booking',
                'description' => "Created booking {$booking->b_ref_no}",
                'actor' => $booking->user?->full_name ?? 'Guest',
                'role' => $booking->user?->role?->role_name ?? 'Guest',
                'occurred_at' => $booking->created_at ?? Carbon::parse($booking->booking_date),
            ]);

        $reservations = Reservation::query()
            ->with(['user.role', 'guest'])
            ->latest('reservation_id')
            ->limit(5)
            ->get()
            ->map(fn (Reservation $reservation): array => [
                'type' => 'Reservation',
                'description' => "Created reservation {$reservation->r_ref_no}",
                'actor' => $reservation->user?->full_name ?? 'Guest',
                'role' => $reservation->user?->role?->role_name ?? 'Guest',
                'occurred_at' => $reservation->created_at ?? Carbon::parse($reservation->reservation_date),
            ]);

        $entranceSlips = EntranceSlip::query()
            ->with('createdBy.role')
            ->latest('entrance_slip_id')
            ->limit(4)
            ->get()
            ->map(fn (EntranceSlip $slip): array => [
                'type' => 'Entrance Slip',
                'description' => "Created entrance slip #{$slip->entrance_slip_id}",
                'actor' => $slip->createdBy?->full_name ?? 'Unknown staff',
                'role' => $slip->createdBy?->role?->role_name ?? 'Unknown role',
                'occurred_at' => $slip->created_at
                    ?? Carbon::parse($slip->date_created.' '.$slip->time_created),
            ]);

        $amenityRequests = AmenityRequest::query()
            ->with('user.role')
            ->latest('amenity_request_id')
            ->limit(4)
            ->get()
            ->map(fn (AmenityRequest $request): array => [
                'type' => 'Amenity Request',
                'description' => "Created amenity request #{$request->amenity_request_id}",
                'actor' => $request->user?->full_name ?? 'System',
                'role' => $request->user?->role?->role_name ?? 'System',
                'occurred_at' => $request->created_at ?? Carbon::parse($request->date_created),
            ]);

        return collect()
            ->concat($payments)
            ->concat($bookings)
            ->concat($reservations)
            ->concat($entranceSlips)
            ->concat($amenityRequests)
            ->sortByDesc(fn (array $item) => $item['occurred_at'])
            ->take($limit)
            ->values();
    }
}

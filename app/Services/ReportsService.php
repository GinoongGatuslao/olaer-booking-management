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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportsService
{
    public function revenueReport(string $startDate, string $endDate, ?int $cashierUserId = null): array
    {
        $query = Payment::query()
            ->with([
                'modeOfPayment',
                'user',
                'booking.guest',
                'reservation.guest',
                'entranceSlip.guest',
            ])
            ->whereBetween('date_paid', [$startDate, $endDate])
            ->where('payment_status', 'Verified')
            ->orderBy('date_paid')
            ->orderBy('payment_id');

        if ($cashierUserId !== null) {
            $query->where('user_id', $cashierUserId);
        }

        $payments = $query->get();

        return [
            'rows' => $payments,
            'total' => round((float) $payments->sum('amount_paid'), 2),
            'by_mode' => $payments
                ->groupBy(fn (Payment $payment): string => $payment->modeOfPayment?->mode_of_payment ?? 'Unknown')
                ->map(fn (Collection $group): float => round((float) $group->sum('amount_paid'), 2)),
            'count' => $payments->count(),
        ];
    }

    public function bookingSummaryReport(string $startDate, string $endDate, ?int $cashierUserId = null): array
    {
        $query = Booking::query()
            ->with([
                'guest',
                'user',
                'details.facility.facilityType',
                'payments.modeOfPayment',
            ])
            ->whereBetween('booking_date', [$startDate, $endDate])
            ->orderBy('booking_date')
            ->orderBy('booking_id');

        if ($cashierUserId !== null) {
            $query->where('user_id', $cashierUserId);
        }

        $bookings = $query->get();

        return [
            'rows' => $bookings,
            'total_price' => round((float) $bookings->sum('total_price'), 2),
            'total_due' => round((float) $bookings->sum('amount_due'), 2),
            'count' => $bookings->count(),
        ];
    }

    public function cancellationReport(string $startDate, string $endDate): array
    {
        $reservations = Reservation::query()
            ->with(['guest', 'user', 'details.facility.facilityType'])
            ->whereNotNull('cancelled_at')
            ->whereBetween('cancelled_at', [$startDate, $endDate])
            ->orderBy('cancelled_at')
            ->orderBy('reservation_id')
            ->get();

        return [
            'rows' => $reservations,
            'count' => $reservations->count(),
        ];
    }

    public function damagedAmenitiesReport(string $startDate, string $endDate): array
    {
        $fines = GuestFine::query()
            ->with([
                'booking.guest',
                'fine.amenity.amenityName',
                'fine.damageType',
                'facility.facilityType',
                'reportedBy',
            ])
            ->whereBetween('date_checked', [$startDate, $endDate])
            ->orderBy('date_checked')
            ->orderBy('guest_fine_id')
            ->get();

        return [
            'rows' => $fines,
            'total_charge' => round((float) $fines->sum('total_charge'), 2),
            'count' => $fines->count(),
        ];
    }

    public function availableFacilitiesReport(string $startDate, string $endDate, ?int $facilityTypeId = null): array
    {
        $blockedFacilityIds = $this->blockedFacilityIds($startDate, $endDate);

        $query = Facility::query()
            ->with(['facilityType', 'prices'])
            ->where('facility_status', 'Available')
            ->whereNotIn('facility_id', $blockedFacilityIds)
            ->orderBy('facility_type_id')
            ->orderBy('facility_name');

        if ($facilityTypeId !== null) {
            $query->where('facility_type_id', $facilityTypeId);
        }

        $facilities = $query->get();

        return [
            'rows' => $facilities,
            'count' => $facilities->count(),
            'by_type' => $facilities
                ->groupBy(fn (Facility $facility): string => $facility->facilityType?->facility_type ?? 'Unknown')
                ->map(fn (Collection $group): int => $group->count()),
        ];
    }

    public function facilityUtilizationReport(string $startDate, string $endDate, ?int $facilityTypeId = null): array
    {
        $query = DB::table('tbl_booking_details')
            ->join('tbl_booking', 'tbl_booking.booking_id', '=', 'tbl_booking_details.booking_id')
            ->join('tbl_facility', 'tbl_facility.facility_id', '=', 'tbl_booking_details.facility_id')
            ->join('tbl_facility_type', 'tbl_facility_type.facility_type_id', '=', 'tbl_facility.facility_type_id')
            ->select([
                'tbl_facility.facility_id',
                'tbl_facility.facility_name',
                'tbl_facility.facility_size',
                'tbl_facility.capacity',
                'tbl_facility_type.facility_type',
                DB::raw('COUNT(tbl_booking_details.booking_details_id) as booking_count'),
            ])
            ->whereBetween('tbl_booking.booking_date', [$startDate, $endDate])
            ->whereNotIn('tbl_booking.status', ['Cancelled', 'Canceled'])
            ->whereNotIn('tbl_booking_details.status', ['Cancelled', 'Canceled', 'Transferred'])
            ->groupBy([
                'tbl_facility.facility_id',
                'tbl_facility.facility_name',
                'tbl_facility.facility_size',
                'tbl_facility.capacity',
                'tbl_facility_type.facility_type',
            ])
            ->orderByDesc('booking_count')
            ->orderBy('tbl_facility.facility_name');

        if ($facilityTypeId !== null) {
            $query->where('tbl_facility.facility_type_id', $facilityTypeId);
        }

        $rows = $query->get();
        $totalBookings = (int) $rows->sum('booking_count');

        $rows = $rows->map(function (object $row) use ($totalBookings): object {
            $row->utilization_percentage = $totalBookings > 0
                ? round(((int) $row->booking_count / $totalBookings) * 100, 2)
                : 0.00;

            return $row;
        });

        return [
            'rows' => $rows,
            'total_bookings' => $totalBookings,
            'count' => $rows->count(),
        ];
    }

    public function tourismEnterpriseMonthlyReport(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1)->startOfMonth()->toDateString();
        $end = CarbonImmutable::create($year, $month, 1)->endOfMonth()->toDateString();

        $slips = EntranceSlip::query()
            ->whereBetween('date_created', [$start, $end])
            ->where('status', 'Paid')
            ->orderBy('date_created')
            ->orderBy('entrance_slip_id')
            ->get();

        return [
            'rows' => $slips,
            'male' => (int) $slips->sum('no_of_Male'),
            'female' => (int) $slips->sum('no_of_Female'),
            'tourist' => (int) $slips->sum('no_of_Tourist'),
            'total_guests' => (int) $slips->sum('no_of_adult') + (int) $slips->sum('no_of_children') + (int) $slips->sum('no_of_PWD_SC'),
            'count' => $slips->count(),
        ];
    }

    public function facilityTypes(): EloquentCollection
    {
        return FacilityType::query()
            ->orderBy('facility_type')
            ->get();
    }

    private function blockedFacilityIds(string $startDate, string $endDate): array
    {
        $bookingBlocked = DB::table('tbl_booking_details')
            ->join('tbl_booking', 'tbl_booking.booking_id', '=', 'tbl_booking_details.booking_id')
            ->whereNotNull('tbl_booking_details.facility_id')
            ->whereNotIn('tbl_booking.status', ['Cancelled', 'Canceled', 'Checked-out'])
            ->whereNotIn('tbl_booking_details.status', ['Cancelled', 'Canceled', 'Transferred', 'Checked-out'])
            ->where('tbl_booking_details.check_in_date', '<=', $endDate)
            ->where('tbl_booking_details.check_out_date', '>=', $startDate)
            ->pluck('tbl_booking_details.facility_id')
            ->all();

        $reservationBlocked = DB::table('tbl_reservation_details')
            ->join('tbl_reservation', 'tbl_reservation.reservation_id', '=', 'tbl_reservation_details.reservation_id')
            ->whereNotNull('tbl_reservation_details.facility_id')
            ->whereNotIn('tbl_reservation.status', ['Cancelled', 'Canceled', 'Expired', 'Converted'])
            ->where('tbl_reservation_details.check_in_date', '<=', $endDate)
            ->where('tbl_reservation_details.check_out_date', '>=', $startDate)
            ->pluck('tbl_reservation_details.facility_id')
            ->all();

        return array_values(array_unique(array_merge($bookingBlocked, $reservationBlocked)));
    }
}

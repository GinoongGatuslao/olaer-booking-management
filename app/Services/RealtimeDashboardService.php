<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\FacilityInspectionRequest;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class RealtimeDashboardService
{
    public function cashier(): array
    {
        return [
            'pending_gcash' => Payment::query()->where('payment_status', 'Pending')->count(),
            'pending_checkouts' => FacilityInspectionRequest::query()->whereIn('status', ['Pending', 'In Progress'])->count(),
            'completed_inspections' => FacilityInspectionRequest::query()->where('status', 'Completed')->whereDate('completed_at', today())->count(),
            'unpaid_bookings' => Booking::query()->where('amount_due', '>', 0)->count(),
            'today_revenue' => (float) Payment::query()
                ->where('payment_status', 'Verified')
                ->whereDate('date_paid', today())
                ->sum('amount_paid'),
        ];
    }

    public function maintenance(): array
    {
        return [
            'inspection_requests' => FacilityInspectionRequest::query()->whereIn('status', ['Pending', 'In Progress'])->count(),
            'amenity_requests' => AmenityRequest::query()->whereIn('amenity_request_status', ['Pending', 'Delivering'])->count(),
            'completed_today' => FacilityInspectionRequest::query()->where('status', 'Completed')->whereDate('completed_at', today())->count(),
        ];
    }

    public function admin(): array
    {
        return [
            'today_revenue' => (float) Payment::query()->where('payment_status', 'Verified')->whereDate('date_paid', today())->sum('amount_paid'),
            'active_bookings' => Booking::query()->whereIn('status', ['Booked', 'Checked-in', 'Partially Checked-out'])->count(),
            'pending_gcash' => Payment::query()->where('payment_status', 'Pending')->count(),
            'facility_usage' => DB::table('tbl_booking_details')->whereIn('status', ['Booked', 'Checked-in'])->count(),
        ];
    }
}

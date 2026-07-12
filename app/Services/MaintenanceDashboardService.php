<?php

namespace App\Services;

use App\Models\AmenityRequest;
use App\Models\FacilityInspection;
use App\Models\FacilityInspectionRequest;
use App\Models\GuestFine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;

class MaintenanceDashboardService
{
    public function overview(int $maintenanceUserId): array
    {
        return [
            'pending_amenity_requests' => AmenityRequest::query()
                ->where('amenity_request_status', 'Pending')
                ->count(),

            'my_active_deliveries' => AmenityRequest::query()
                ->where('amenity_request_status', 'Delivering')
                ->where('assigned_to_user_id', $maintenanceUserId)
                ->count(),

            'my_deliveries_today' => AmenityRequest::query()
                ->where('amenity_request_status', 'Delivered')
                ->where('assigned_to_user_id', $maintenanceUserId)
                ->whereDate('delivered_at', today())
                ->count(),

            'pending_inspection_requests' => FacilityInspectionRequest::query()
                ->where('status', 'Pending')
                ->count(),

            'my_inspections_in_progress' => FacilityInspectionRequest::query()
                ->where('status', 'In Progress')
                ->where('assigned_to_user_id', $maintenanceUserId)
                ->count(),

            'my_completed_inspections_today' => FacilityInspectionRequest::query()
                ->where('status', 'Completed')
                ->where('assigned_to_user_id', $maintenanceUserId)
                ->whereDate('completed_at', today())
                ->count(),

            'issues_reported_today' => GuestFine::query()
                ->where('reported_by_user_id', $maintenanceUserId)
                ->whereDate('date_checked', today())
                ->count(),

            'charges_reported_today' => (float) GuestFine::query()
                ->where('reported_by_user_id', $maintenanceUserId)
                ->whereDate('date_checked', today())
                ->sum('total_charge'),
        ];
    }

    /**
     * Paid requests that are unassigned, plus deliveries currently assigned
     * to the logged-in maintenance staff.
     */
    public function amenityWorkQueue(int $maintenanceUserId, int $limit = 8): Collection
    {
        return AmenityRequest::query()
            ->with([
                'booking.guest',
                'details.amenity.amenityName',
                'details.facility',
                'assignedTo',
            ])
            ->where(function (Builder $query) use ($maintenanceUserId): void {
                $query->where('amenity_request_status', 'Pending')
                    ->orWhere(function (Builder $query) use ($maintenanceUserId): void {
                        $query->where('amenity_request_status', 'Delivering')
                            ->where('assigned_to_user_id', $maintenanceUserId);
                    });
            })
            ->orderByRaw("CASE WHEN amenity_request_status = 'Delivering' THEN 0 ELSE 1 END")
            ->oldest('amenity_request_id')
            ->limit($limit)
            ->get();
    }

    /**
     * Cashier-sent inspection requests only. Pending requests are available
     * to the team; in-progress requests shown here belong to the current user.
     */
    public function inspectionWorkQueue(int $maintenanceUserId, int $limit = 8): Collection
    {
        return FacilityInspectionRequest::query()
            ->with([
                'booking.guest',
                'bookingDetail',
                'facility.facilityType',
                'requestedBy',
                'assignedTo',
            ])
            ->where(function (Builder $query) use ($maintenanceUserId): void {
                $query->where('status', 'Pending')
                    ->orWhere(function (Builder $query) use ($maintenanceUserId): void {
                        $query->where('status', 'In Progress')
                            ->where('assigned_to_user_id', $maintenanceUserId);
                    });
            })
            ->orderByRaw("CASE WHEN status = 'In Progress' THEN 0 ELSE 1 END")
            ->oldest('requested_at')
            ->limit($limit)
            ->get();
    }

    public function myRecentCompletedInspections(int $maintenanceUserId, int $limit = 6): Collection
    {
        return FacilityInspection::query()
            ->with([
                'booking.guest',
                'facility.facilityType',
                'items.amenity.amenityName',
                'items.fine',
            ])
            ->where('inspected_by_user_id', $maintenanceUserId)
            ->latest('inspected_at')
            ->limit($limit)
            ->get();
    }

    public function myRecentReportedIssues(int $maintenanceUserId, int $limit = 6): Collection
    {
        return GuestFine::query()
            ->with([
                'booking.guest',
                'facility',
                'fine.amenity.amenityName',
                'fine.damageType',
            ])
            ->where('reported_by_user_id', $maintenanceUserId)
            ->latest('guest_fine_id')
            ->limit($limit)
            ->get();
    }

    public function amenityItemSummary(AmenityRequest $request): string
    {
        return $request->details
            ->map(function ($detail): string {
                $name = $detail->amenity?->amenityName?->amenity_name ?? 'Amenity';
                $quantity = (int) $detail->amenity_quantity;
                $facility = $detail->facility?->facility_name ?? 'Unassigned facility';

                return "{$name} × {$quantity} → {$facility}";
            })
            ->implode(', ');
    }

    public function fineDescription(GuestFine $guestFine): string
    {
        $fine = $guestFine->fine;

        if ($fine === null) {
            return 'Fine record';
        }

        if ($fine->fine_type === 'Amenity Fine') {
            $amenity = $fine->amenity?->amenityName?->amenity_name ?? 'Amenity';
            $damage = $fine->damageType?->damage_type ?? 'Issue';

            return "{$amenity} — {$damage}";
        }

        return $fine->situational_fine
            ?: ($fine->situational_fine_description ?: 'Situational fine');
    }
}

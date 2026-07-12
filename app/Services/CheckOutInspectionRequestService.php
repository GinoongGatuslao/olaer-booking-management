<?php

namespace App\Services;

use App\Models\BookingDetail;
use App\Models\FacilityInspectionRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckOutInspectionRequestService
{
    public function latestRequestForDetail(int $bookingDetailsId): ?FacilityInspectionRequest
    {
        return FacilityInspectionRequest::query()
            ->with(['inspection', 'assignedTo', 'requestedBy'])
            ->where('booking_details_id', $bookingDetailsId)
            ->latest('facility_inspection_request_id')
            ->first();
    }

    public function requestInspection(int $bookingDetailsId, int $cashierUserId, ?string $notes = null): FacilityInspectionRequest
    {
        return DB::transaction(function () use ($bookingDetailsId, $cashierUserId, $notes): FacilityInspectionRequest {
            $detail = BookingDetail::query()
                ->with(['booking', 'facility'])
                ->lockForUpdate()
                ->findOrFail($bookingDetailsId);

            if ((string) $detail->status !== 'Checked-in') {
                throw ValidationException::withMessages([
                    'inspection' => 'Inspection can only be requested for checked-in facilities.',
                ]);
            }

            if ($detail->booking === null || in_array((string) $detail->booking->status, ['Cancelled', 'Checked-out'], true)) {
                throw ValidationException::withMessages([
                    'inspection' => 'This booking can no longer receive an inspection request.',
                ]);
            }

            if ($detail->facility === null || $detail->facility_id === null) {
                throw ValidationException::withMessages([
                    'inspection' => 'This booking detail has no assigned facility to inspect.',
                ]);
            }

            $activeRequest = FacilityInspectionRequest::query()
                ->where('booking_details_id', $detail->booking_details_id)
                ->whereIn('status', ['Pending', 'In Progress'])
                ->latest('facility_inspection_request_id')
                ->first();

            if ($activeRequest !== null) {
                return $activeRequest->fresh(['booking.guest', 'bookingDetail', 'facility', 'requestedBy', 'assignedTo', 'inspection']);
            }

            $completedRequestExists = FacilityInspectionRequest::query()
                ->where('booking_details_id', $detail->booking_details_id)
                ->where('status', 'Completed')
                ->exists();

            if ($completedRequestExists) {
                throw ValidationException::withMessages([
                    'inspection' => 'This facility already has a completed inspection request.',
                ]);
            }

            return FacilityInspectionRequest::query()->create([
                'booking_id' => $detail->booking_id,
                'booking_details_id' => $detail->booking_details_id,
                'facility_id' => $detail->facility_id,
                'requested_by_user_id' => $cashierUserId,
                'status' => 'Pending',
                'request_notes' => $notes,
                'requested_at' => now(),
            ])->fresh(['booking.guest', 'bookingDetail', 'facility', 'requestedBy', 'assignedTo', 'inspection']);
        });
    }

    public function pendingRequestsForMaintenance(): Collection
    {
        return FacilityInspectionRequest::query()
            ->with(['booking.guest', 'bookingDetail', 'facility.facilityType', 'requestedBy', 'assignedTo'])
            ->whereIn('status', ['Pending', 'In Progress'])
            ->orderBy('requested_at')
            ->get();
    }

    public function acceptRequest(int $requestId, int $maintenanceUserId): FacilityInspectionRequest
    {
        return DB::transaction(function () use ($requestId, $maintenanceUserId): FacilityInspectionRequest {
            $request = FacilityInspectionRequest::query()
                ->lockForUpdate()
                ->findOrFail($requestId);

            if (! in_array((string) $request->status, ['Pending', 'In Progress'], true)) {
                throw ValidationException::withMessages([
                    'inspection' => 'Only pending or in-progress inspection requests can be accepted.',
                ]);
            }

            $request->update([
                'status' => 'In Progress',
                'assigned_to_user_id' => $maintenanceUserId,
                'accepted_at' => $request->accepted_at ?? now(),
            ]);

            return $request->fresh(['booking.guest', 'bookingDetail', 'facility', 'requestedBy', 'assignedTo', 'inspection']);
        });
    }

    public function markLatestRequestCompleted(int $bookingDetailsId, int $maintenanceUserId): void
    {
        $request = FacilityInspectionRequest::query()
            ->where('booking_details_id', $bookingDetailsId)
            ->whereIn('status', ['Pending', 'In Progress'])
            ->latest('facility_inspection_request_id')
            ->first();

        if ($request === null) {
            return;
        }

        $request->update([
            'status' => 'Completed',
            'assigned_to_user_id' => $request->assigned_to_user_id ?? $maintenanceUserId,
            'accepted_at' => $request->accepted_at ?? now(),
            'completed_at' => now(),
        ]);
    }

    public function completedRequestExistsForDetail(int $bookingDetailsId): bool
    {
        return FacilityInspectionRequest::query()
            ->where('booking_details_id', $bookingDetailsId)
            ->where('status', 'Completed')
            ->whereHas('inspection')
            ->exists();
    }
}

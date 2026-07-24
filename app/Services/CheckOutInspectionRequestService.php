<?php

namespace App\Services;

use App\Models\BookingDetail;
use App\Models\FacilityInspection;
use App\Models\FacilityInspectionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckOutInspectionRequestService
{
    public function latestRequestForDetail(
        int $bookingDetailsId,
    ): ?FacilityInspectionRequest {
        return FacilityInspectionRequest::query()
            ->with([
                'inspection',
                'assignedTo',
                'requestedBy',
            ])
            ->where(
                'booking_details_id',
                $bookingDetailsId,
            )
            ->latest(
                'facility_inspection_request_id',
            )
            ->first();
    }

    public function requestInspection(
        int $bookingDetailsId,
        int $cashierUserId,
        ?string $notes = null,
    ): FacilityInspectionRequest {
        return DB::transaction(function () use (
            $bookingDetailsId,
            $cashierUserId,
            $notes,
        ): FacilityInspectionRequest {
            $this->guardUserRole(
                $cashierUserId,
                'Cashier',
                'Only cashiers can send facility inspection requests.',
            );

            $detail = BookingDetail::query()
                ->with([
                    'booking',
                    'facility',
                ])
                ->lockForUpdate()
                ->findOrFail($bookingDetailsId);

            if ((string) $detail->status !== 'Checked-in') {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'Inspection can only be requested for checked-in facilities.',
                ]);
            }

            if (
                $detail->booking === null
                || in_array(
                    (string) $detail->booking->status,
                    [
                        'Cancelled',
                        'Checked-out',
                    ],
                    true,
                )
            ) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'This booking can no longer receive an inspection request.',
                ]);
            }

            if (
                $detail->facility === null
                || $detail->facility_id === null
            ) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'This booking detail has no assigned facility to inspect.',
                ]);
            }

            $activeRequest =
                FacilityInspectionRequest::query()
                    ->where(
                        'booking_details_id',
                        $detail->booking_details_id,
                    )
                    ->whereIn(
                        'status',
                        [
                            'Pending',
                            'In Progress',
                        ],
                    )
                    ->latest(
                        'facility_inspection_request_id',
                    )
                    ->first();

            if ($activeRequest !== null) {
                return $activeRequest->fresh(
                    $this->requestRelations(),
                );
            }

            $completedRequestExists =
                FacilityInspectionRequest::query()
                    ->where(
                        'booking_details_id',
                        $detail->booking_details_id,
                    )
                    ->where(
                        'status',
                        'Completed',
                    )
                    ->exists();

            if ($completedRequestExists) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'This facility already has a completed inspection request.',
                ]);
            }

            return FacilityInspectionRequest::query()
                ->create([
                    'booking_id' =>
                        $detail->booking_id,
                    'booking_details_id' =>
                        $detail->booking_details_id,
                    'facility_id' =>
                        $detail->facility_id,
                    'requested_by_user_id' =>
                        $cashierUserId,
                    'status' => 'Pending',
                    'request_notes' => $notes,
                    'requested_at' => now(),
                ])
                ->fresh($this->requestRelations());
        });
    }

    public function pendingRequestsForMaintenance(): Collection
    {
        return FacilityInspectionRequest::query()
            ->with([
                'booking.guest',
                'bookingDetail',
                'facility.facilityType',
                'requestedBy',
                'assignedTo',
            ])
            ->whereIn(
                'status',
                [
                    'Pending',
                    'In Progress',
                ],
            )
            ->orderBy('requested_at')
            ->get();
    }

    public function acceptRequest(
        int $requestId,
        int $maintenanceUserId,
    ): FacilityInspectionRequest {
        return DB::transaction(function () use (
            $requestId,
            $maintenanceUserId,
        ): FacilityInspectionRequest {
            $this->guardUserRole(
                $maintenanceUserId,
                'Maintenance Staff',
                'Only maintenance staff can accept facility inspection requests.',
            );

            $request =
                FacilityInspectionRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($requestId);

            if (
                $request->assigned_to_user_id !== null
                && (int) $request->assigned_to_user_id
                    !== $maintenanceUserId
            ) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'This inspection request is already assigned to another maintenance staff member.',
                ]);
            }

            if (
                (string) $request->status
                === 'In Progress'
            ) {
                if (
                    $request->assigned_to_user_id === null
                    || $request->accepted_at === null
                ) {
                    throw ValidationException::withMessages([
                        'inspection' =>
                            'This in-progress inspection request has incomplete assignment data.',
                    ]);
                }

                return $request->fresh(
                    $this->requestRelations(),
                );
            }

            if (
                (string) $request->status
                !== 'Pending'
            ) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'Only pending inspection requests can be accepted.',
                ]);
            }

            $request->update([
                'status' => 'In Progress',
                'assigned_to_user_id' =>
                    $maintenanceUserId,
                'accepted_at' =>
                    $request->accepted_at ?? now(),
            ]);

            return $request->fresh(
                $this->requestRelations(),
            );
        });
    }

    public function markLatestRequestCompleted(
        int $bookingDetailsId,
        int $maintenanceUserId,
    ): FacilityInspectionRequest {
        $this->guardUserRole(
            $maintenanceUserId,
            'Maintenance Staff',
            'Only maintenance staff can complete facility inspection requests.',
        );

        return DB::transaction(function () use (
            $bookingDetailsId,
            $maintenanceUserId,
        ): FacilityInspectionRequest {
            $request =
                FacilityInspectionRequest::query()
                    ->where(
                        'booking_details_id',
                        $bookingDetailsId,
                    )
                    ->latest(
                        'facility_inspection_request_id',
                    )
                    ->lockForUpdate()
                    ->first();

            if ($request === null) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'A cashier-created inspection request is required before completing the inspection.',
                ]);
            }

            if (
                (string) $request->status
                === 'Pending'
            ) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'Accept the inspection request before completing it.',
                ]);
            }

            if (
                $request->assigned_to_user_id === null
                || $request->accepted_at === null
            ) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'The inspection request must have a valid maintenance assignment before completion.',
                ]);
            }

            if (
                (int) $request->assigned_to_user_id
                !== $maintenanceUserId
            ) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'Only the assigned maintenance staff member can complete this inspection request.',
                ]);
            }

            if (
                ! in_array(
                    (string) $request->status,
                    [
                        'In Progress',
                        'Completed',
                    ],
                    true,
                )
            ) {
                throw ValidationException::withMessages([
                    'inspection' =>
                        'This inspection request can no longer be completed.',
                ]);
            }

            $this->guardInspectionRecorded(
                $bookingDetailsId,
                $maintenanceUserId,
            );

            if (
                (string) $request->status
                === 'Completed'
            ) {
                return $request->fresh(
                    $this->requestRelations(),
                );
            }

            $request->update([
                'status' => 'Completed',
                'completed_at' => now(),
            ]);

            return $request->fresh(
                $this->requestRelations(),
            );
        });
    }

    public function completedRequestExistsForDetail(
        int $bookingDetailsId,
    ): bool {
        return FacilityInspectionRequest::query()
            ->where(
                'booking_details_id',
                $bookingDetailsId,
            )
            ->where(
                'status',
                'Completed',
            )
            ->whereHas('inspection')
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private function requestRelations(): array
    {
        return [
            'booking.guest',
            'bookingDetail',
            'facility',
            'requestedBy',
            'assignedTo',
            'inspection',
        ];
    }

    private function guardInspectionRecorded(
        int $bookingDetailsId,
        int $maintenanceUserId,
    ): void {
        $inspectionExists =
            FacilityInspection::query()
                ->where(
                    'booking_details_id',
                    $bookingDetailsId,
                )
                ->where(
                    'inspected_by_user_id',
                    $maintenanceUserId,
                )
                ->exists();

        if (! $inspectionExists) {
            throw ValidationException::withMessages([
                'inspection' =>
                    'The assigned maintenance staff member must record the facility inspection result before completing the request.',
            ]);
        }
    }

    private function guardUserRole(
        int $userId,
        string $requiredRole,
        string $message,
    ): void {
        $user = User::query()
            ->with('role')
            ->findOrFail($userId);

        if (
            (string) $user->role?->role_name
            !== $requiredRole
        ) {
            throw ValidationException::withMessages([
                'inspection' => $message,
            ]);
        }
    }
}

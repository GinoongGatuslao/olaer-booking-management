<?php

namespace App\Services;

use App\Models\AmenityRequestDetail;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\FacilityAmenity;
use App\Models\FacilityInspection;
use App\Models\FacilityInspectionRequest;
use App\Models\Fine;
use App\Models\GuestFine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class FacilityInspectionWorkflowService
{
    /**
     * Returns all items maintenance must inspect for one checked-in facility:
     * - inclusive facility amenities; and
     * - delivered rentable amenities from amenity requests.
     */
    public function checklistFor(int $bookingDetailsId): array
    {
        $detail = BookingDetail::query()
            ->with(['booking', 'facility'])
            ->findOrFail($bookingDetailsId);

        $booking = $detail->booking;

        if ($booking === null || $detail->facility_id === null) {
            return [];
        }

        $facilityItems = FacilityAmenity::query()
            ->with(['amenity.amenityName'])
            ->where('facility_id', $detail->facility_id)
            ->get()
            ->map(function (FacilityAmenity $facilityAmenity): array {
                return $this->makeChecklistItem(
                    source: 'facility_amenity',
                    sourceId: (int) $facilityAmenity->facility_amenity_id,
                    amenityId: (int) $facilityAmenity->amenity_id,
                    expectedQuantity: (int) $facilityAmenity->amenity_quantity,
                    amenityName: $facilityAmenity->amenity?->amenityName?->amenity_name ?? 'Amenity',
                    sourceLabel: 'Inclusive facility amenity'
                );
            })
            ->all();

        $requestedItems = AmenityRequestDetail::query()
            ->select('tbl_amenity_request_details.*')
            ->join('tbl_amenity_request', 'tbl_amenity_request.amenity_request_id', '=', 'tbl_amenity_request_details.amenity_request_id')
            ->with(['amenity.amenityName', 'amenityRequest'])
            ->where('tbl_amenity_request.booking_id', $booking->booking_id)
            ->where('tbl_amenity_request.amenity_request_status', 'Delivered')
            ->where('tbl_amenity_request_details.facility_id', $detail->facility_id)
            ->get()
            ->map(function (AmenityRequestDetail $requestDetail): array {
                return $this->makeChecklistItem(
                    source: 'amenity_request',
                    sourceId: (int) $requestDetail->amenity_request_detail_id,
                    amenityId: (int) $requestDetail->amenity_id,
                    expectedQuantity: (int) $requestDetail->amenity_quantity,
                    amenityName: $requestDetail->amenity?->amenityName?->amenity_name ?? 'Amenity',
                    sourceLabel: 'Delivered requested amenity'
                );
            })
            ->all();

        return array_values(array_merge($facilityItems, $requestedItems));
    }

    public function markNoDamage(int $bookingDetailsId, int $maintenanceUserId, ?string $remarks = null): FacilityInspection
    {
        DB::beginTransaction();

        try {
            $detail = BookingDetail::query()
                ->with(['booking', 'facility'])
                ->lockForUpdate()
                ->findOrFail($bookingDetailsId);

            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail((int) $detail->booking_id);

            $this->guardMaintenanceUser($maintenanceUserId);
            $this->guardCanInspect($detail, $booking);
            $this->guardAssignedInspectionRequest(
                $bookingDetailsId,
                $maintenanceUserId,
            );
            $this->guardNoExistingFines($booking, $detail);

            $inspection = $this->upsertInspection($detail, $booking, $maintenanceUserId, 'Cleared', $remarks);
            app(CheckOutInspectionRequestService::class)->markLatestRequestCompleted($bookingDetailsId, $maintenanceUserId);

            foreach ($this->checklistFor($bookingDetailsId) as $item) {
                $inspection->items()->updateOrCreate(
                    [
                        'item_source' => $item['source'],
                        'source_id' => $item['source_id'],
                        'fine_id' => null,
                    ],
                    [
                        'amenity_id' => $item['amenity_id'],
                        'expected_quantity' => $item['expected_quantity'],
                        'condition_status' => 'Complete',
                        'fine_quantity' => 0,
                        'total_charge' => 0,
                        'notes' => null,
                    ]
                );
            }

            DB::commit();

            return $inspection->fresh(['booking.guest', 'facility', 'inspectedBy', 'items.amenity.amenityName', 'items.fine']);
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    public function recordFine(
        int $bookingDetailsId,
        int $fineId,
        int $quantity,
        int $maintenanceUserId,
        ?string $remarks = null,
        ?string $itemSource = null,
        ?int $sourceId = null
    ): GuestFine {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Fine quantity must be at least 1.');
        }

        DB::beginTransaction();

        try {
            $detail = BookingDetail::query()
                ->with(['booking', 'facility'])
                ->lockForUpdate()
                ->findOrFail($bookingDetailsId);

            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail((int) $detail->booking_id);

            $fine = Fine::query()
                ->with(['amenity.amenityName', 'damageType'])
                ->lockForUpdate()
                ->findOrFail($fineId);

            $this->guardMaintenanceUser($maintenanceUserId);
            $this->guardCanInspect($detail, $booking);
            $this->guardAssignedInspectionRequest(
                $bookingDetailsId,
                $maintenanceUserId,
                allowCompletedDamageInspection: true,
            );

            $sourceItem = $this->resolveChecklistItem($bookingDetailsId, $itemSource, $sourceId);

            if ($sourceItem !== null && $fine->amenity_id !== null && (int) $fine->amenity_id !== (int) $sourceItem['amenity_id']) {
                throw new InvalidArgumentException('The selected fine does not match the selected checklist amenity.');
            }

            $totalCharge = round(((float) $fine->fine_charge) * $quantity, 2);
            $inspection = $this->upsertInspection($detail, $booking, $maintenanceUserId, 'Damage Found', $remarks);
            app(CheckOutInspectionRequestService::class)->markLatestRequestCompleted($bookingDetailsId, $maintenanceUserId);

            if ($sourceItem !== null) {
                $inspection->items()->updateOrCreate(
                    [
                        'item_source' => $sourceItem['source'],
                        'source_id' => $sourceItem['source_id'],
                        'fine_id' => $fine->fine_id,
                    ],
                    [
                        'amenity_id' => $sourceItem['amenity_id'],
                        'expected_quantity' => $sourceItem['expected_quantity'],
                        'condition_status' => $this->conditionFromFine($fine),
                        'fine_quantity' => $quantity,
                        'total_charge' => $totalCharge,
                        'notes' => $remarks,
                    ]
                );
            }

            $guestFine = GuestFine::query()->create([
                'booking_id' => $booking->booking_id,
                'fine_id' => $fine->fine_id,
                'quantity' => $quantity,
                'facility_id' => $detail->facility_id,
                'total_charge' => $totalCharge,
                'date_checked' => Carbon::today()->toDateString(),
                'reported_by_user_id' => $maintenanceUserId,
            ]);

            $booking->update([
                'total_price' => round(((float) $booking->total_price) + $totalCharge, 2),
                'amount_due' => round(((float) $booking->amount_due) + $totalCharge, 2),
            ]);

            DB::commit();

            return $guestFine->fresh(['booking.guest', 'fine.amenity.amenityName', 'fine.damageType', 'facility', 'reportedBy']);
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    private function makeChecklistItem(string $source, int $sourceId, int $amenityId, int $expectedQuantity, string $amenityName, string $sourceLabel): array
    {
        $fineCount = Fine::query()
            ->where('fine_type', 'Amenity Fine')
            ->where('amenity_id', $amenityId)
            ->count();

        return [
            'key' => $source . ':' . $sourceId,
            'source' => $source,
            'source_id' => $sourceId,
            'amenity_id' => $amenityId,
            'expected_quantity' => max($expectedQuantity, 1),
            'amenity_name' => $amenityName,
            'source_label' => $sourceLabel,
            'fine_count' => $fineCount,
        ];
    }

    private function resolveChecklistItem(int $bookingDetailsId, ?string $itemSource, ?int $sourceId): ?array
    {
        if ($itemSource === null || $sourceId === null || $sourceId < 1) {
            return null;
        }

        foreach ($this->checklistFor($bookingDetailsId) as $item) {
            if ($item['source'] === $itemSource && (int) $item['source_id'] === $sourceId) {
                return $item;
            }
        }

        throw new InvalidArgumentException('Selected checklist item is not valid for this checked-in facility.');
    }

    private function upsertInspection(BookingDetail $detail, Booking $booking, int $maintenanceUserId, string $status, ?string $remarks): FacilityInspection
    {
        return FacilityInspection::query()->updateOrCreate(
            ['booking_details_id' => $detail->booking_details_id],
            [
                'booking_id' => $booking->booking_id,
                'facility_id' => $detail->facility_id,
                'inspected_by_user_id' => $maintenanceUserId,
                'inspection_status' => $status,
                'remarks' => $remarks,
                'inspected_at' => Carbon::now(),
            ]
        );
    }

    private function conditionFromFine(Fine $fine): string
    {
        $damageType = strtolower((string) ($fine->damageType?->damage_type ?? ''));
        $situational = strtolower((string) $fine->situational_fine);
        $text = $damageType . ' ' . $situational;

        if (str_contains($text, 'missing')) {
            return 'Missing';
        }

        if (str_contains($text, 'stain')) {
            return 'Stained';
        }

        if (str_contains($text, 'damage') || str_contains($text, 'broken')) {
            return 'Damaged';
        }

        return 'Issue Found';
    }

    private function guardMaintenanceUser(int $maintenanceUserId): void
    {
        $user = User::query()
            ->with('role')
            ->findOrFail($maintenanceUserId);

        $roleName = $user->role !== null ? (string) $user->role->role_name : '';

        if ($roleName !== 'Maintenance Staff') {
            throw new InvalidArgumentException('Only maintenance staff can record facility inspections.');
        }
    }

    private function guardCanInspect(BookingDetail $detail, Booking $booking): void
    {
        if ((string) $detail->status !== 'Checked-in') {
            throw new InvalidArgumentException('Only checked-in booking details can be inspected.');
        }

        if (in_array((string) $booking->status, ['Cancelled', 'Checked-out'], true)) {
            throw new InvalidArgumentException('This booking can no longer be inspected.');
        }

        if ($detail->facility_id === null || $detail->facility === null) {
            throw new InvalidArgumentException('This booking detail has no assigned facility to inspect.');
        }
    }

    private function guardAssignedInspectionRequest(
        int $bookingDetailsId,
        int $maintenanceUserId,
        bool $allowCompletedDamageInspection = false,
    ): void {
        $request = FacilityInspectionRequest::query()
            ->where('booking_details_id', $bookingDetailsId)
            ->latest('facility_inspection_request_id')
            ->lockForUpdate()
            ->first();

        if ($request === null) {
            throw new InvalidArgumentException(
                'A cashier-created inspection request is required before maintenance can inspect this facility.',
            );
        }

        if ($request->assigned_to_user_id === null) {
            throw new InvalidArgumentException(
                'Accept the inspection request before recording the inspection result.',
            );
        }

        if ((int) $request->assigned_to_user_id !== $maintenanceUserId) {
            throw new InvalidArgumentException(
                'Only the assigned maintenance staff member can complete this inspection.',
            );
        }

        if ((string) $request->status === 'In Progress') {
            return;
        }

        if (
            $allowCompletedDamageInspection
            && (string) $request->status === 'Completed'
        ) {
            $inspectionIsDamageFound =
                FacilityInspection::query()
                    ->where(
                        'booking_details_id',
                        $bookingDetailsId,
                    )
                    ->where(
                        'inspection_status',
                        'Damage Found',
                    )
                    ->exists();

            if ($inspectionIsDamageFound) {
                return;
            }
        }

        throw new InvalidArgumentException(
            'This inspection request is not active for the selected maintenance staff member.',
        );
    }

    private function guardNoExistingFines(Booking $booking, BookingDetail $detail): void
    {
        $hasFine = GuestFine::query()
            ->where('booking_id', $booking->booking_id)
            ->where('facility_id', $detail->facility_id)
            ->exists();

        if ($hasFine) {
            throw new InvalidArgumentException('This facility already has recorded fines. It cannot be marked as no damage.');
        }
    }
}

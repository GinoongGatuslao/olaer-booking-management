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
use App\Models\InspectionDraftFine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FacilityInspectionWorkflowService
{
    public function __construct(
        private readonly DecimalMoneyService $money,
        private readonly TransactionLedgerService $ledger,
        private readonly CheckOutInspectionRequestService $inspectionRequests,
    ) {}

    public function checklistFor(int $bookingDetailsId): array
    {
        $detail = BookingDetail::query()
            ->with(['booking', 'facility'])
            ->findOrFail($bookingDetailsId);

        if ($detail->booking === null || $detail->facility_id === null) {
            return [];
        }

        $facilityItems = FacilityAmenity::query()
            ->with(['amenity.amenityName'])
            ->where('facility_id', $detail->facility_id)
            ->get()
            ->map(fn (FacilityAmenity $item): array => $this->makeChecklistItem(
                source: 'facility_amenity',
                sourceId: (int) $item->facility_amenity_id,
                amenityId: (int) $item->amenity_id,
                expectedQuantity: (int) $item->amenity_quantity,
                amenityName: $item->amenity?->amenityName?->amenity_name ?? 'Amenity',
                sourceLabel: 'Inclusive facility amenity',
            ))
            ->all();

        $requestedItems = AmenityRequestDetail::query()
            ->select('tbl_amenity_request_details.*')
            ->join(
                'tbl_amenity_request',
                'tbl_amenity_request.amenity_request_id',
                '=',
                'tbl_amenity_request_details.amenity_request_id',
            )
            ->with(['amenity.amenityName', 'amenityRequest'])
            ->where('tbl_amenity_request.booking_id', $detail->booking_id)
            ->where('tbl_amenity_request.amenity_request_status', 'Delivered')
            ->where('tbl_amenity_request_details.facility_id', $detail->facility_id)
            ->get()
            ->map(fn (AmenityRequestDetail $item): array => $this->makeChecklistItem(
                source: 'amenity_request',
                sourceId: (int) $item->amenity_request_detail_id,
                amenityId: (int) $item->amenity_id,
                expectedQuantity: (int) $item->amenity_quantity,
                amenityName: $item->amenity?->amenityName?->amenity_name ?? 'Amenity',
                sourceLabel: 'Delivered requested amenity',
            ))
            ->all();

        return array_values(array_merge($facilityItems, $requestedItems));
    }

    /**
     * No-damage completion is itself a Complete Inspection operation.
     */
    public function markNoDamage(
        int $bookingDetailsId,
        int $maintenanceUserId,
        ?string $remarks = null,
    ): FacilityInspection {
        return DB::transaction(function () use (
            $bookingDetailsId,
            $maintenanceUserId,
            $remarks,
        ): FacilityInspection {
            [$detail, $booking, $request] = $this->lockActiveInspection(
                $bookingDetailsId,
                $maintenanceUserId,
            );

            if (InspectionDraftFine::query()
                ->where('facility_inspection_request_id', $request->facility_inspection_request_id)
                ->where('status', 'Draft')
                ->exists()) {
                throw new InvalidArgumentException(
                    'Remove all draft fines before completing the inspection as no damage.',
                );
            }

            $inspection = $this->upsertInspection(
                $request,
                $detail,
                $booking,
                $maintenanceUserId,
                'Cleared',
                $remarks,
            );

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
                        'total_charge' => '0.00',
                        'notes' => null,
                    ],
                );
            }

            $this->inspectionRequests->markLatestRequestCompleted(
                $bookingDetailsId,
                $maintenanceUserId,
            );

            return $inspection->fresh([
                'booking.guest',
                'facility',
                'inspectedBy',
                'items.amenity.amenityName',
                'items.fine',
            ]);
        }, attempts: 3);
    }

    /**
     * Store or replace a draft fine. No GuestFine or booking liability is created here.
     */
    public function recordFine(
        int $bookingDetailsId,
        int $fineId,
        int $quantity,
        int $maintenanceUserId,
        ?string $remarks = null,
        ?string $itemSource = null,
        ?int $sourceId = null,
    ): InspectionDraftFine {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Fine quantity must be at least 1.');
        }

        return DB::transaction(function () use (
            $bookingDetailsId,
            $fineId,
            $quantity,
            $maintenanceUserId,
            $remarks,
            $itemSource,
            $sourceId,
        ): InspectionDraftFine {
            [$detail, $booking, $request] = $this->lockActiveInspection(
                $bookingDetailsId,
                $maintenanceUserId,
            );

            $fine = Fine::query()
                ->with(['amenity.amenityName', 'damageType'])
                ->lockForUpdate()
                ->findOrFail($fineId);

            $sourceItem = $this->resolveChecklistItem(
                $bookingDetailsId,
                $itemSource,
                $sourceId,
            );

            if (
                $sourceItem !== null
                && $fine->amenity_id !== null
                && (int) $fine->amenity_id !== (int) $sourceItem['amenity_id']
            ) {
                throw new InvalidArgumentException(
                    'The selected fine does not match the selected checklist amenity.',
                );
            }

            if ($sourceItem !== null && $quantity > (int) $sourceItem['expected_quantity']) {
                throw new InvalidArgumentException(
                    'Fine quantity cannot exceed the checklist expected quantity.',
                );
            }

            $unitCharge = $this->money->normalize((string) $fine->fine_charge);
            $totalCharge = $this->money->multiply($unitCharge, $quantity);

            $identity = [
                'facility_inspection_request_id' => $request->facility_inspection_request_id,
                'booking_details_id' => $detail->booking_details_id,
                'fine_id' => $fine->fine_id,
                'item_source' => $sourceItem['source'] ?? null,
                'source_id' => $sourceItem['source_id'] ?? null,
                'status' => 'Draft',
            ];

            $draft = InspectionDraftFine::query()
                ->where($identity)
                ->lockForUpdate()
                ->first();

            if ($draft === null) {
                $draft = InspectionDraftFine::query()->create([
                    ...$identity,
                    'quantity' => $quantity,
                    'unit_charge_snapshot' => $unitCharge,
                    'total_charge' => $totalCharge,
                    'remarks' => $remarks,
                    'created_by_user_id' => $maintenanceUserId,
                    'updated_by_user_id' => null,
                ]);
            } else {
                $draft->update([
                    'quantity' => $quantity,
                    'unit_charge_snapshot' => $unitCharge,
                    'total_charge' => $totalCharge,
                    'remarks' => $remarks,
                    'updated_by_user_id' => $maintenanceUserId,
                ]);
            }

            $this->upsertInspection(
                $request,
                $detail,
                $booking,
                $maintenanceUserId,
                'In Progress',
                $remarks,
            );

            return $draft->fresh(['fine.amenity.amenityName', 'fine.damageType']);
        }, attempts: 3);
    }

    public function removeDraftFine(
        int $draftFineId,
        int $maintenanceUserId,
    ): void {
        DB::transaction(function () use ($draftFineId, $maintenanceUserId): void {
            $draft = InspectionDraftFine::query()
                ->with('inspectionRequest')
                ->lockForUpdate()
                ->findOrFail($draftFineId);

            $request = $draft->inspectionRequest;

            if (
                $request === null
                || $request->status !== 'In Progress'
                || (int) $request->assigned_to_user_id !== $maintenanceUserId
                || $draft->status !== 'Draft'
            ) {
                throw new InvalidArgumentException(
                    'Only draft fines on your active inspection can be removed.',
                );
            }

            $this->guardMaintenanceUser($maintenanceUserId);
            $draft->delete();
        }, attempts: 3);
    }

    /**
     * Atomic publication point: validate drafts, create GuestFine liabilities,
     * update the booking ledger, complete the request, and lock all findings.
     */
    public function completeInspection(
        int $bookingDetailsId,
        int $maintenanceUserId,
        ?string $remarks = null,
    ): FacilityInspection {
        return DB::transaction(function () use (
            $bookingDetailsId,
            $maintenanceUserId,
            $remarks,
        ): FacilityInspection {
            [$detail, $booking, $request] = $this->lockActiveInspection(
                $bookingDetailsId,
                $maintenanceUserId,
            );

            $drafts = InspectionDraftFine::query()
                ->with(['fine.amenity.amenityName', 'fine.damageType'])
                ->where('facility_inspection_request_id', $request->facility_inspection_request_id)
                ->where('booking_details_id', $detail->booking_details_id)
                ->where('status', 'Draft')
                ->orderBy('inspection_draft_fine_id')
                ->lockForUpdate()
                ->get();

            if ($drafts->isEmpty()) {
                throw new InvalidArgumentException(
                    'No draft fines exist. Use the No Damage completion action instead.',
                );
            }

            $inspection = $this->upsertInspection(
                $request,
                $detail,
                $booking,
                $maintenanceUserId,
                'Damage Found',
                $remarks,
            );

            $publishedTotal = '0.00';

            foreach ($drafts as $draft) {
                $fine = $draft->fine;

                if ($fine === null) {
                    throw new InvalidArgumentException(
                        'A draft fine references missing master data and cannot be published.',
                    );
                }

                $guestFine = GuestFine::query()->create([
                    'booking_id' => $booking->booking_id,
                    'fine_id' => $fine->fine_id,
                    'facility_id' => $detail->facility_id,
                    'booking_details_id' => $detail->booking_details_id,
                    'quantity' => $draft->quantity,
                    'item_source' => $draft->item_source,
                    'source_id' => $draft->source_id,
                    'total_charge' => $draft->total_charge,
                    'date_checked' => Carbon::today()->toDateString(),
                    'reported_by_user_id' => $maintenanceUserId,
                ]);

                $sourceItem = $draft->item_source !== null && $draft->source_id !== null
                    ? $this->resolveChecklistItem(
                        $bookingDetailsId,
                        (string) $draft->item_source,
                        (int) $draft->source_id,
                    )
                    : null;

                $inspection->items()->create([
                    'item_source' => $sourceItem['source'] ?? null,
                    'source_id' => $sourceItem['source_id'] ?? null,
                    'amenity_id' => $sourceItem['amenity_id'] ?? $fine->amenity_id,
                    'expected_quantity' => $sourceItem['expected_quantity'] ?? $draft->quantity,
                    'condition_status' => $this->conditionFromFine($fine),
                    'fine_id' => $fine->fine_id,
                    'fine_quantity' => $draft->quantity,
                    'total_charge' => $draft->total_charge,
                    'notes' => $draft->remarks,
                ]);

                $draft->update([
                    'status' => 'Published',
                    'guest_fine_id' => $guestFine->guest_fine_id,
                    'published_at' => now(),
                    'updated_by_user_id' => $maintenanceUserId,
                ]);

                $publishedTotal = $this->money->add(
                    $publishedTotal,
                    (string) $draft->total_charge,
                );
            }

            // Increment the persisted parent first so legacy transactions that
            // predate immutable detail snapshots keep their historical core
            // total while the ledger still recomputes normalized bookings.
            $booking->update([
                'total_price' => $this->money->add(
                    (string) $booking->total_price,
                    $publishedTotal,
                ),
                'amount_due' => $this->money->add(
                    (string) $booking->amount_due,
                    $publishedTotal,
                ),
            ]);

            $summary = $this->ledger->summaryForBooking(
                $booking->fresh([
                    'details',
                    'amenityRequests.details',
                    'guestFines',
                    'payments',
                    'reservation.payments',
                ]),
            );
            $booking->update([
                'total_price' => $summary['total'],
                'amount_due' => $summary['balance'],
            ]);

            $this->inspectionRequests->markLatestRequestCompleted(
                $bookingDetailsId,
                $maintenanceUserId,
            );

            return $inspection->fresh([
                'booking.guest',
                'facility',
                'inspectedBy',
                'items.amenity.amenityName',
                'items.fine',
            ]);
        }, attempts: 3);
    }

    private function makeChecklistItem(
        string $source,
        int $sourceId,
        int $amenityId,
        int $expectedQuantity,
        string $amenityName,
        string $sourceLabel,
    ): array {
        return [
            'key' => $source.':'.$sourceId,
            'source' => $source,
            'source_id' => $sourceId,
            'amenity_id' => $amenityId,
            'expected_quantity' => max($expectedQuantity, 1),
            'amenity_name' => $amenityName,
            'source_label' => $sourceLabel,
            'fine_count' => Fine::query()
                ->whereIn('fine_type', ['Amenity', 'Amenity Fine'])
                ->where('amenity_id', $amenityId)
                ->count(),
        ];
    }

    private function resolveChecklistItem(
        int $bookingDetailsId,
        ?string $itemSource,
        ?int $sourceId,
    ): ?array {
        if ($itemSource === null || $sourceId === null || $sourceId < 1) {
            return null;
        }

        foreach ($this->checklistFor($bookingDetailsId) as $item) {
            if ($item['source'] === $itemSource && (int) $item['source_id'] === $sourceId) {
                return $item;
            }
        }

        throw new InvalidArgumentException(
            'Selected checklist item is not valid for this checked-in facility.',
        );
    }

    /**
     * @return array{0: BookingDetail, 1: Booking, 2: FacilityInspectionRequest}
     */
    private function lockActiveInspection(
        int $bookingDetailsId,
        int $maintenanceUserId,
    ): array {
        $this->guardMaintenanceUser($maintenanceUserId);

        $detail = BookingDetail::query()
            ->with(['booking', 'facility'])
            ->lockForUpdate()
            ->findOrFail($bookingDetailsId);
        $booking = Booking::query()
            ->lockForUpdate()
            ->findOrFail((int) $detail->booking_id);

        $this->guardCanInspect($detail, $booking);

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

        if (
            $request->status !== 'In Progress'
            || (int) $request->assigned_to_user_id !== $maintenanceUserId
        ) {
            throw new InvalidArgumentException(
                'Only the assigned maintenance staff can modify an active inspection.',
            );
        }

        return [$detail, $booking, $request];
    }

    private function upsertInspection(
        FacilityInspectionRequest $request,
        BookingDetail $detail,
        Booking $booking,
        int $maintenanceUserId,
        string $status,
        ?string $remarks,
    ): FacilityInspection {
        return FacilityInspection::query()->updateOrCreate(
            ['booking_details_id' => $detail->booking_details_id],
            [
                'facility_inspection_request_id' => $request->facility_inspection_request_id,
                'booking_id' => $booking->booking_id,
                'facility_id' => $detail->facility_id,
                'inspected_by_user_id' => $maintenanceUserId,
                'inspection_status' => $status,
                'remarks' => $remarks,
                'inspected_at' => Carbon::now(),
            ],
        );
    }

    private function conditionFromFine(Fine $fine): string
    {
        $text = strtolower(
            trim((string) ($fine->damageType?->damage_type ?? ''))
            .' '
            .trim((string) $fine->situational_fine),
        );

        return match (true) {
            str_contains($text, 'missing') => 'Missing',
            str_contains($text, 'stain') => 'Stained',
            str_contains($text, 'damage'),
            str_contains($text, 'broken') => 'Damaged',
            default => 'Issue Found',
        };
    }

    private function guardMaintenanceUser(int $maintenanceUserId): void
    {
        $user = User::query()->with('role')->findOrFail($maintenanceUserId);

        if (
            $user->status !== 'Active'
            || $user->role?->role_name !== 'Maintenance Staff'
        ) {
            throw new InvalidArgumentException(
                'Only active maintenance staff can record facility inspections.',
            );
        }
    }

    private function guardCanInspect(BookingDetail $detail, Booking $booking): void
    {
        if ((string) $detail->status !== 'Checked-in') {
            throw new InvalidArgumentException(
                'Only checked-in booking details can be inspected.',
            );
        }

        if (! in_array(
            (string) $booking->status,
            ['Checked-in', 'Partially Checked-in', 'Partially Checked-out'],
            true,
        )) {
            throw new InvalidArgumentException(
                'This booking can no longer be inspected.',
            );
        }

        if ($detail->facility_id === null || $detail->facility === null) {
            throw new InvalidArgumentException(
                'This booking detail has no assigned facility to inspect.',
            );
        }
    }
}

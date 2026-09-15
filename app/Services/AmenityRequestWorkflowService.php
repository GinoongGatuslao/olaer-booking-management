<?php

namespace App\Services;

use App\Models\Amenity;
use App\Models\AmenityRequest;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class AmenityRequestWorkflowService
{
    /**
     * Authorized staff create a billable amenity request for a checked-in booking.
     *
     * Correct real-life flow:
     * 1. Cashier records request.
     * 2. System adds amenity charge to the booking balance.
     * 3. Request immediately becomes Pending for maintenance delivery.
     * 4. Maintenance accepts and delivers without waiting for payment.
     * 5. Guest pays the amenity charge during checkout/final billing.
     */
    public function createBillableRequest(array $data): AmenityRequest
    {
        $bookingId = (int) ($data['booking_id'] ?? 0);
        $bookingDetailId = (int) ($data['booking_detail_id'] ?? 0);
        $facilityId = (int) ($data['facility_id'] ?? 0);
        $creatorUserId = (int) ($data['user_id'] ?? 0);
        $items = $data['items'] ?? [];

        if (
            $bookingId < 1
            || ($bookingDetailId < 1 && $facilityId < 1)
        ) {
            throw new InvalidArgumentException(
                'Select a valid checked-in booking and delivery facility.',
            );
        }

        $creatorRole = $this->guardAmenityRequestCreator(
            $creatorUserId,
        );

        if (
            $creatorRole === 'Maintenance Staff'
            && $bookingDetailId < 1
        ) {
            throw new InvalidArgumentException(
                'Maintenance staff must select a checked-in booking detail.',
            );
        }

        $cleanItems = $this->normalizeItems($items);

        DB::beginTransaction();

        try {
            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail($bookingId);

            $this->guardBookingCanRequestAmenities($booking);
            $bookingDetail = $this->checkedInBookingDetail(
                $booking,
                $bookingDetailId,
                $facilityId,
            );

            $quote = $this->quoteItems($cleanItems);
            $isMaintenanceCreator =
                $creatorRole === 'Maintenance Staff';

            $request = AmenityRequest::query()->create([
                'booking_id' => $booking->booking_id,
                'amenity_request_status' => $isMaintenanceCreator ? 'Delivering' : 'Pending',
                'total_price' => $quote['total'],
                'date_created' => Carbon::today()->toDateString(),
                'user_id' => $creatorUserId,
                'assigned_to_user_id' => $isMaintenanceCreator ? $creatorUserId : null,
                'delivered_at' => null,
                'cancelled_at' => null,
            ]);

            foreach ($quote['items'] as $item) {
                $request->details()->create([
                    'facility_id' => $bookingDetail->facility_id,
                    'amenity_id' => $item['amenity']->amenity_id,
                    'amenity_quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['line_total'],
                ]);
            }

            $booking->update([
                'total_price' => round(
                    (float) $booking->total_price
                    + $quote['total'],
                    2,
                ),
                'amount_due' => round(
                    (float) $booking->amount_due
                    + $quote['total'],
                    2,
                ),
            ]);

            DB::commit();

            return $request->fresh([
                'booking.guest',
                'details.amenity.amenityName',
                'details.facility',
                'user',
                'assignedTo',
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * Modify only pending, undelivered requests that have not been accepted by maintenance.
     */
    public function updateBillableRequest(
        int $amenityRequestId,
        int $facilityId,
        array $items,
        int $cashierUserId,
    ): AmenityRequest {
        if ($amenityRequestId < 1 || $facilityId < 1) {
            throw new InvalidArgumentException(
                'Select a valid amenity request and facility.',
            );
        }

        $cleanItems = $this->normalizeItems($items);

        DB::beginTransaction();

        try {
            $request = AmenityRequest::query()
                ->with(['booking'])
                ->lockForUpdate()
                ->findOrFail($amenityRequestId);

            $this->guardCashierUser($cashierUserId);

            if (
                $request->amenity_request_status !== 'Pending'
                || $request->assigned_to_user_id !== null
            ) {
                throw new InvalidArgumentException(
                    'Only pending, undelivered amenity requests can be modified.',
                );
            }

            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail((int) $request->booking_id);

            $this->guardBookingCanRequestAmenities($booking);
            $this->checkedInBookingDetail(
                $booking,
                0,
                $facilityId,
            );

            $oldTotal = round((float) $request->total_price, 2);
            $quote = $this->quoteItems($cleanItems);
            $difference = round($quote['total'] - $oldTotal, 2);

            $newTotalPrice = round(
                (float) $booking->total_price + $difference,
                2,
            );

            $newAmountDue = round(
                (float) $booking->amount_due + $difference,
                2,
            );

            if ($newTotalPrice < 0 || $newAmountDue < 0) {
                throw new InvalidArgumentException(
                    'Amenity request cannot be modified because the booking balance is inconsistent. Please review payments first.',
                );
            }

            $request->details()->delete();

            foreach ($quote['items'] as $item) {
                $request->details()->create([
                    'facility_id' => $facilityId,
                    'amenity_id' => $item['amenity']->amenity_id,
                    'amenity_quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['line_total'],
                ]);
            }

            $request->update([
                'total_price' => $quote['total'],
            ]);

            $booking->update([
                'total_price' => $newTotalPrice,
                'amount_due' => $newAmountDue,
            ]);

            DB::commit();

            return $request->fresh([
                'booking.guest',
                'details.amenity.amenityName',
                'details.facility',
                'user',
                'assignedTo',
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * Cancel only while pending and unaccepted. Delivered amenities remain billable.
     */
    public function cancelUnpaidRequest(
        int $amenityRequestId,
        int $cashierUserId,
    ): void {
        DB::beginTransaction();

        try {
            $request = AmenityRequest::query()
                ->with('booking')
                ->lockForUpdate()
                ->findOrFail($amenityRequestId);

            $this->guardCashierUser($cashierUserId);

            if (
                $request->amenity_request_status !== 'Pending'
                || $request->assigned_to_user_id !== null
            ) {
                throw new InvalidArgumentException(
                    'Only pending, undelivered amenity requests can be cancelled.',
                );
            }

            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail((int) $request->booking_id);

            $total = round((float) $request->total_price, 2);

            if (
                round((float) $booking->amount_due, 2) < $total
                || round((float) $booking->total_price, 2) < $total
            ) {
                throw new InvalidArgumentException(
                    'Amenity request cannot be cancelled because the booking balance is inconsistent. Please review payments first.',
                );
            }

            $request->update([
                'amenity_request_status' => 'Cancelled',
                'cancelled_at' => Carbon::now(),
            ]);

            $booking->update([
                'total_price' => round(
                    (float) $booking->total_price - $total,
                    2,
                ),
                'amount_due' => round(
                    (float) $booking->amount_due - $total,
                    2,
                ),
            ]);

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * Legacy compatibility only.
     *
     * Older builds used Awaiting Payment. Under the revised rule, these records
     * are moved to Pending as long as the parent booking is still checked in.
     */
    public function releasePaidRequestsForBooking(int $bookingId): int
    {
        return DB::transaction(function () use ($bookingId): int {
            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail($bookingId);

            if (! $this->bookingCanReceiveAmenityDelivery($booking)) {
                return 0;
            }

            return AmenityRequest::query()
                ->where('booking_id', $booking->booking_id)
                ->where('amenity_request_status', 'Awaiting Payment')
                ->update([
                    'amenity_request_status' => 'Pending',
                ]);
        });
    }

    public function acceptRequest(
        int $amenityRequestId,
        int $maintenanceUserId,
    ): AmenityRequest {
        DB::beginTransaction();

        try {
            $this->guardMaintenanceUser($maintenanceUserId);

            $request = AmenityRequest::query()
                ->with('booking')
                ->lockForUpdate()
                ->findOrFail($amenityRequestId);

            if ($request->amenity_request_status !== 'Pending') {
                throw new InvalidArgumentException(
                    'Only pending amenity requests can be accepted.',
                );
            }

            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail((int) $request->booking_id);

            $this->guardBookingCanReceiveAmenityDelivery($booking);

            if (! $request->details()->exists()) {
                throw new InvalidArgumentException(
                    'Amenity request has no delivery items.',
                );
            }

            $request->update([
                'amenity_request_status' => 'Delivering',
                'assigned_to_user_id' => $maintenanceUserId,
            ]);

            DB::commit();

            return $request->fresh([
                'booking.guest',
                'details.amenity.amenityName',
                'details.facility',
                'user',
                'assignedTo',
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    public function markDelivered(
        int $amenityRequestId,
        int $maintenanceUserId,
    ): AmenityRequest {
        DB::beginTransaction();

        try {
            $this->guardMaintenanceUser($maintenanceUserId);

            $request = AmenityRequest::query()
                ->with('booking')
                ->lockForUpdate()
                ->findOrFail($amenityRequestId);

            if ($request->amenity_request_status === 'Delivered') {
                if ((int) $request->assigned_to_user_id !== $maintenanceUserId) {
                    throw new InvalidArgumentException(
                        'Only the assigned maintenance staff can confirm this delivered request.',
                    );
                }

                DB::commit();

                return $request->fresh([
                    'booking.guest',
                    'details.amenity.amenityName',
                    'details.facility',
                    'user',
                    'assignedTo',
                ]);
            }

            if ($request->amenity_request_status !== 'Delivering') {
                throw new InvalidArgumentException(
                    'Only requests in Delivering status can be marked as delivered.',
                );
            }

            if ((int) $request->assigned_to_user_id !== $maintenanceUserId) {
                throw new InvalidArgumentException(
                    'Only the assigned maintenance staff can mark this request as delivered.',
                );
            }

            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail((int) $request->booking_id);

            $this->guardBookingCanReceiveAmenityDelivery($booking);

            $request->update([
                'amenity_request_status' => 'Delivered',
                'delivered_at' => $request->delivered_at ?? Carbon::now(),
            ]);

            DB::commit();

            return $request->fresh([
                'booking.guest',
                'details.amenity.amenityName',
                'details.facility',
                'user',
                'assignedTo',
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    private function normalizeItems(array $items): array
    {
        $clean = [];

        foreach ($items as $item) {
            $amenityId = (int) ($item['amenity_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($amenityId > 0 && $quantity > 0) {
                $clean[] = [
                    'amenity_id' => $amenityId,
                    'quantity' => $quantity,
                ];
            }
        }

        if ($clean === []) {
            throw new InvalidArgumentException(
                'Add at least one amenity and quantity.',
            );
        }

        return $clean;
    }

    private function quoteItems(array $items): array
    {
        $quotedItems = [];
        $total = 0.00;

        foreach ($items as $item) {
            $amenity = Amenity::query()
                ->with('amenityName')
                ->lockForUpdate()
                ->findOrFail($item['amenity_id']);

            if (
                strtolower((string) $amenity->amenity_type)
                !== 'rentable'
            ) {
                throw new InvalidArgumentException(
                    'Only rentable amenities can be requested and billed.',
                );
            }

            $price = round((float) $amenity->amenity_price, 2);

            if ($price <= 0) {
                throw new InvalidArgumentException(
                    'Rentable amenities must have a price greater than zero.',
                );
            }

            $lineTotal = round(
                $price * (int) $item['quantity'],
                2,
            );

            $total = round($total + $lineTotal, 2);

            $quotedItems[] = [
                'amenity' => $amenity,
                'quantity' => (int) $item['quantity'],
                'unit_price' => $price,
                'line_total' => $lineTotal,
            ];
        }

        return [
            'items' => $quotedItems,
            'total' => $total,
        ];
    }

    private function guardBookingCanRequestAmenities(
        Booking $booking,
    ): void {
        if (
            ! in_array(
                (string) $booking->status,
                ['Checked-in', 'Partially Checked-in'],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Amenities can only be requested for checked-in bookings.',
            );
        }
    }

    private function checkedInBookingDetail(
        Booking $booking,
        int $bookingDetailId,
        int $facilityId,
    ): BookingDetail {
        $bookingDetail = BookingDetail::query()
            ->where('booking_id', $booking->booking_id)
            ->where('status', 'Checked-in')
            ->when(
                $bookingDetailId > 0,
                fn ($query) => $query->whereKey($bookingDetailId),
            )
            ->when(
                $facilityId > 0,
                fn ($query) => $query->where(
                    'facility_id',
                    $facilityId,
                ),
            )
            ->whereNotNull('facility_id')
            ->lockForUpdate()
            ->first();

        if ($bookingDetail === null) {
            throw new InvalidArgumentException(
                'The booking detail and delivery facility must belong to the selected checked-in booking.',
            );
        }

        return $bookingDetail;
    }

    private function bookingCanReceiveAmenityDelivery(
        Booking $booking,
    ): bool {
        return in_array(
            (string) $booking->status,
            ['Checked-in', 'Partially Checked-in'],
            true,
        );
    }

    private function guardBookingCanReceiveAmenityDelivery(
        Booking $booking,
    ): void {
        if (! $this->bookingCanReceiveAmenityDelivery($booking)) {
            throw new InvalidArgumentException(
                'Amenity delivery is only allowed for checked-in bookings.',
            );
        }
    }

    private function guardCashierUser(int $cashierUserId): void
    {
        if ($cashierUserId < 1) {
            throw new InvalidArgumentException(
                'A logged-in cashier is required to create an amenity request.',
            );
        }

        $user = User::query()
            ->with('role')
            ->findOrFail($cashierUserId);

        if ($user->role?->role_name !== 'Cashier') {
            throw new InvalidArgumentException(
                'Only a Cashier can create amenity requests.',
            );
        }
    }

    private function guardAmenityRequestCreator(
        int $creatorUserId,
    ): string {
        if ($creatorUserId < 1) {
            throw new InvalidArgumentException(
                'A logged-in staff member is required to create an amenity request.',
            );
        }

        $roleName = User::query()
            ->with('role')
            ->findOrFail($creatorUserId)
            ->role
            ?->role_name;

        if (! in_array($roleName, ['Cashier', 'Maintenance Staff'], true)) {
            throw new InvalidArgumentException(
                'Only Cashier or Maintenance Staff users can create amenity requests.',
            );
        }

        return $roleName;
    }

    private function guardMaintenanceUser(int $maintenanceUserId): void
    {
        $user = User::query()
            ->with('role')
            ->findOrFail($maintenanceUserId);

        if ($user->role?->role_name !== 'Maintenance Staff') {
            throw new InvalidArgumentException(
                'Only maintenance staff can accept or deliver amenity requests.',
            );
        }
    }
}

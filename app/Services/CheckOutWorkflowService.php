<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDetail;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class CheckOutWorkflowService
{
    public function checkOutBookingDetail(int $bookingDetailsId, int $userId): BookingDetail
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

            $this->guardCanCheckOut($detail, $booking);

            $detail->update([
                'status' => 'Checked-out',
                'user_id' => $userId,
            ]);

            if ($detail->facility !== null) {
                $detail->facility->update([
                    'facility_status' => 'Available',
                ]);
            }

            $remainingOpenDetails = BookingDetail::query()
                ->where('booking_id', $booking->booking_id)
                ->whereNotIn('status', ['Checked-out', 'Cancelled'])
                ->count();

            $booking->update([
                'status' => $remainingOpenDetails === 0 ? 'Checked-out' : 'Partially Checked-out',
            ]);

            DB::commit();

            return $detail->fresh(['booking.guest', 'facility.facilityType']);
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    private function guardCanCheckOut(BookingDetail $detail, Booking $booking): void
    {
        if ((string) $detail->status !== 'Checked-in') {
            throw new InvalidArgumentException('Only checked-in booking details can be checked out.');
        }

        if (in_array((string) $booking->status, ['Cancelled', 'Checked-out'], true)) {
            throw new InvalidArgumentException('This booking can no longer be checked out.');
        }

        if ($detail->facility === null) {
            throw new InvalidArgumentException('This booking detail has no assigned facility.');
        }

        $inspectionRequestIsCompleted = app(CheckOutInspectionRequestService::class)
            ->completedRequestExistsForDetail((int) $detail->booking_details_id);

        if (! $inspectionRequestIsCompleted) {
            throw new InvalidArgumentException('A completed maintenance inspection request is required before check-out.');
        }

        if ((float) $booking->amount_due > 0) {
            throw new InvalidArgumentException('This guest still has an unpaid balance. Settle the bill before check-out.');
        }
    }
}

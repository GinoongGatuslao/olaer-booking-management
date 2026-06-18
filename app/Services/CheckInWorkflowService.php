<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class CheckInWorkflowService
{
    public function checkInBookingDetail(int $bookingDetailsId, int $userId): BookingDetail
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

            $this->guardCanCheckIn($detail, $booking);

            $now = Carbon::now();

            $detail->update([
                'status' => 'Checked-in',
                'check_in_time' => $now->format('H:i:s'),
                'user_id' => $userId,
            ]);

            if ($detail->facility !== null) {
                $detail->facility->update([
                    'facility_status' => 'Occupied',
                ]);
            }

            $remainingNotCheckedInDetails = BookingDetail::query()
                ->where('booking_id', $booking->booking_id)
                ->whereNotIn('status', ['Checked-in', 'Checked-out', 'Cancelled'])
                ->count();

            $booking->update([
                'status' => $remainingNotCheckedInDetails === 0 ? 'Checked-in' : 'Partially Checked-in',
            ]);

            DB::commit();

            return $detail->fresh(['booking.guest', 'facility.facilityType']);
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    private function guardCanCheckIn(BookingDetail $detail, Booking $booking): void
    {
        $bookingStatus = (string) $booking->status;
        $detailStatus = (string) $detail->status;
        $allowedDetailStatuses = ['Booked', 'Rescheduled', 'Transferred', 'Extended'];

        if (in_array($bookingStatus, ['Cancelled', 'Checked-out'], true)) {
            throw new InvalidArgumentException('This booking can no longer be checked in.');
        }

        if (! in_array($detailStatus, $allowedDetailStatuses, true)) {
            throw new InvalidArgumentException('Only booked, rescheduled, transferred, or extended booking details can be checked in.');
        }

        if ((float) $booking->amount_due > 0) {
            throw new InvalidArgumentException('The booking must be fully paid before check-in.');
        }

        if ($detail->facility === null) {
            throw new InvalidArgumentException('This booking detail has no assigned facility.');
        }

        if ((string) $detail->facility->facility_status === 'Unavailable') {
            throw new InvalidArgumentException('The assigned facility is currently marked unavailable.');
        }

        if (Carbon::parse($detail->check_out_date)->lt(Carbon::today())) {
            throw new InvalidArgumentException('This booking has already passed its check-out date.');
        }
    }
}

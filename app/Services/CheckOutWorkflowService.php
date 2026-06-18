<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\Fine;
use App\Models\GuestFine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class CheckOutWorkflowService
{
    public function recordFineForBookingDetail(int $bookingDetailsId, int $fineId, int $facilityId, int $quantity): GuestFine
    {
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
                ->lockForUpdate()
                ->findOrFail($fineId);

            $this->guardCanRecordFine($detail, $booking, $facilityId);

            $totalCharge = round(((float) $fine->fine_charge) * $quantity, 2);

            $guestFine = GuestFine::query()->create([
                'booking_id' => $booking->booking_id,
                'fine_id' => $fine->fine_id,
                'quantity' => $quantity,
                'facility_id' => $facilityId,
                'total_charge' => $totalCharge,
                'date_checked' => Carbon::today()->toDateString(),
            ]);

            $booking->update([
                'total_price' => round(((float) $booking->total_price) + $totalCharge, 2),
                'amount_due' => round(((float) $booking->amount_due) + $totalCharge, 2),
            ]);

            DB::commit();

            return $guestFine->fresh(['booking.guest', 'fine.amenity.amenityName', 'fine.damageType', 'facility']);
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

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

    private function guardCanRecordFine(BookingDetail $detail, Booking $booking, int $facilityId): void
    {
        if ((string) $detail->status !== 'Checked-in') {
            throw new InvalidArgumentException('Fines can only be recorded for checked-in booking details.');
        }

        if (in_array((string) $booking->status, ['Cancelled', 'Checked-out'], true)) {
            throw new InvalidArgumentException('This booking can no longer receive fines.');
        }

        $facilityIsPartOfBooking = BookingDetail::query()
            ->where('booking_id', $booking->booking_id)
            ->where('facility_id', $facilityId)
            ->exists();

        if (! $facilityIsPartOfBooking) {
            throw new InvalidArgumentException('The selected facility is not part of this booking.');
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

        if ((float) $booking->amount_due > 0) {
            throw new InvalidArgumentException('This guest still has an unpaid balance. Settle the bill before check-out.');
        }

        if ($detail->facility === null) {
            throw new InvalidArgumentException('This booking detail has no assigned facility.');
        }
    }
}

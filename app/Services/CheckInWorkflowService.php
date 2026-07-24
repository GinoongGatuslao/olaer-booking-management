<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class CheckInWorkflowService
{
    public function __construct(
        private readonly FacilityScheduleLockService $scheduleLock,
    ) {}

    public function checkInBookingDetail(
        int $bookingDetailsId,
        int $userId,
    ): BookingDetail {
        $this->guardCashier($userId);

        DB::beginTransaction();

        try {
            $detail = BookingDetail::query()
                ->with('booking')
                ->lockForUpdate()
                ->findOrFail($bookingDetailsId);

            if ($detail->facility_id === null) {
                throw new InvalidArgumentException(
                    'This booking detail has no assigned facility.',
                );
            }

            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail((int) $detail->booking_id);

            $facility = $this->scheduleLock
                ->lockOne((int) $detail->facility_id)
                ->load('facilityType');

            $detail->setRelation('booking', $booking);
            $detail->setRelation('facility', $facility);

            $this->guardCanCheckIn($detail, $booking);

            $detail->update([
                'status' => 'Checked-in',
                'check_in_time' => Carbon::now()->format('H:i:s'),
                'user_id' => $userId,
            ]);

            $facility->update([
                'facility_status' => 'Occupied',
            ]);

            $booking->update([
                'status' => $this->resolveParentBookingStatus(
                    (int) $booking->booking_id,
                ),
            ]);

            DB::commit();

            return $detail->fresh([
                'booking.guest',
                'facility.facilityType',
            ]);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    private function guardCashier(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException(
                'A logged-in cashier is required to check in guests.',
            );
        }

        $user = User::query()
            ->with('role')
            ->findOrFail($userId);

        if ($user->role?->role_name !== 'Cashier') {
            throw new InvalidArgumentException(
                'Only a Cashier may check in guests.',
            );
        }
    }

    private function guardCanCheckIn(
        BookingDetail $detail,
        Booking $booking,
    ): void {
        $allowedBookingStatuses = [
            'Booked',
            'Partially Checked-in',
            'Partially Checked-out',
        ];

        if (
            ! in_array(
                (string) $booking->status,
                $allowedBookingStatuses,
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'This booking can no longer be checked in.',
            );
        }

        $allowedDetailStatuses = [
            'Booked',
            'Rescheduled',
            'Transferred',
            'Extended',
        ];

        if (
            ! in_array(
                (string) $detail->status,
                $allowedDetailStatuses,
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Only booked, rescheduled, transferred, or extended booking details can be checked in.',
            );
        }

        if (round((float) $booking->amount_due, 2) > 0.009) {
            throw new InvalidArgumentException(
                'The booking must be fully paid before check-in.',
            );
        }

        if ($detail->facility === null) {
            throw new InvalidArgumentException(
                'This booking detail has no assigned facility.',
            );
        }

        if (
            (string) $detail->facility->facility_status
            !== 'Available'
        ) {
            throw new InvalidArgumentException(
                'The assigned facility must be Available before check-in.',
            );
        }

        $today = Carbon::today();
        $checkInDate = Carbon::parse($detail->check_in_date)
            ->startOfDay();
        $checkOutDate = Carbon::parse($detail->check_out_date)
            ->startOfDay();

        if ($checkInDate->gt($today)) {
            throw new InvalidArgumentException(
                'The guest cannot be checked in before the scheduled check-in date.',
            );
        }

        if ($checkOutDate->lt($today)) {
            throw new InvalidArgumentException(
                'This booking has already passed its check-out date.',
            );
        }
    }

    private function resolveParentBookingStatus(
        int $bookingId,
    ): string {
        $openStatuses = [
            'Booked',
            'Rescheduled',
            'Transferred',
            'Extended',
        ];

        $openDetails = BookingDetail::query()
            ->where('booking_id', $bookingId)
            ->whereIn('status', $openStatuses)
            ->count();

        $checkedInDetails = BookingDetail::query()
            ->where('booking_id', $bookingId)
            ->where('status', 'Checked-in')
            ->count();

        $checkedOutDetails = BookingDetail::query()
            ->where('booking_id', $bookingId)
            ->where('status', 'Checked-out')
            ->count();

        if (
            $checkedOutDetails > 0
            && ($checkedInDetails > 0 || $openDetails > 0)
        ) {
            return 'Partially Checked-out';
        }

        if ($checkedInDetails > 0 && $openDetails > 0) {
            return 'Partially Checked-in';
        }

        return 'Checked-in';
    }
}

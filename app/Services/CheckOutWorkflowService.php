<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class CheckOutWorkflowService
{
    public function __construct(
        private readonly FacilityScheduleLockService $scheduleLock,
        private readonly FacilityScheduleBlockService $scheduleBlocks,
    ) {}

    public function checkOutBookingDetail(
        int $bookingDetailsId,
        int $userId,
    ): BookingDetail {
        $this->guardCashier($userId);

        DB::beginTransaction();

        try {
            $detail = BookingDetail::query()
                ->lockForUpdate()
                ->findOrFail($bookingDetailsId);

            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail((int) $detail->booking_id);

            $detail->setRelation('booking', $booking);

            $facility = $this->scheduleLock->lockOne((int) $detail->facility_id);
            $detail->setRelation('facility', $facility);

            $this->guardCanCheckOut($detail, $booking);

            $detail->update([
                'status' => 'Checked-out',
                'user_id' => $userId,
            ]);

            $this->scheduleBlocks->releaseBookingDetails([$detail]);

            if ($detail->facility !== null) {
                $otherCheckedInDetailsExist = BookingDetail::query()
                    ->where(
                        'facility_id',
                        $detail->facility_id,
                    )
                    ->where(
                        'booking_details_id',
                        '!=',
                        $detail->booking_details_id,
                    )
                    ->where('status', 'Checked-in')
                    ->exists();

                if (! $otherCheckedInDetailsExist) {
                    $detail->facility->update([
                        'facility_status' => 'Available',
                    ]);
                }
            }

            $remainingOpenDetails = BookingDetail::query()
                ->where('booking_id', $booking->booking_id)
                ->whereNotIn(
                    'status',
                    [
                        'Checked-out',
                        'Cancelled',
                        'Payment Rejected',
                        'Rejected',
                    ],
                )
                ->count();

            $booking->update([
                'status' => $remainingOpenDetails === 0
                    ? 'Checked-out'
                    : 'Partially Checked-out',
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
                'A logged-in cashier is required to check out bookings.',
            );
        }

        $user = User::query()
            ->with('role')
            ->findOrFail($userId);

        if (! $user->role()->where('role_name', 'Cashier')->exists()) {
            throw new InvalidArgumentException(
                'Only a Cashier may check out bookings.',
            );
        }
    }

    private function guardCanCheckOut(
        BookingDetail $detail,
        Booking $booking,
    ): void {
        if ((string) $detail->status !== 'Checked-in') {
            throw new InvalidArgumentException(
                'Only checked-in booking details can be checked out.',
            );
        }

        $lockedBookingStatuses = [
            'Cancelled',
            'Checked-out',
            'Payment Rejected',
            'Rejected',
            'Pending Verification',
        ];

        if (
            in_array(
                (string) $booking->status,
                $lockedBookingStatuses,
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'This booking can no longer be checked out.',
            );
        }

        if ($detail->facility === null) {
            throw new InvalidArgumentException(
                'This booking detail has no assigned facility.',
            );
        }

        $inspectionRequestIsCompleted = app(
            CheckOutInspectionRequestService::class,
        )->completedRequestExistsForDetail(
            (int) $detail->booking_details_id,
        );

        if (! $inspectionRequestIsCompleted) {
            throw new InvalidArgumentException(
                'A completed maintenance inspection request is required before check-out.',
            );
        }

        if (round((float) $booking->amount_due, 2) > 0.00) {
            throw new InvalidArgumentException(
                'This guest still has an unpaid balance. Settle the bill before check-out.',
            );
        }
    }
}

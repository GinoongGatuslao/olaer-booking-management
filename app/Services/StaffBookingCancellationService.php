<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cancels a whole booking, the same way StaffReservationCancellationService
 * cancels a reservation. Refunds are requested afterwards through
 * RefundRequestWorkflowService::submitForBooking().
 */
class StaffBookingCancellationService
{
    /** Facility statuses that mean the guest has not arrived yet. */
    private const OPEN_DETAIL_STATUSES = ['Booked', 'Rescheduled', 'Transferred', 'Extended'];

    public function __construct(
        private readonly FacilityScheduleLockService $scheduleLock,
        private readonly FacilityScheduleBlockService $scheduleBlocks,
    ) {}

    /** Whether this booking can still be cancelled (nobody has checked in yet). */
    public function canCancel(Booking $booking): bool
    {
        if ((string) $booking->status !== 'Booked') {
            return false;
        }

        $booking->loadMissing('details');

        return $booking->details->every(fn (BookingDetail $detail): bool => in_array(
            (string) $detail->status,
            [...self::OPEN_DETAIL_STATUSES, 'Cancelled'],
            true,
        ));
    }

    public function cancel(int $bookingId, string $reason, int $cashierUserId): Booking
    {
        $reason = $this->cleanReason($reason);

        $this->guardCashier($cashierUserId);

        return $this->run($bookingId, $reason);
    }

    /**
     * Guest self-service cancellation. The guest has already proven ownership
     * with a one-time code, so there is no cashier check; every other rule is the same.
     */
    public function cancelForGuest(int $bookingId, string $reason): Booking
    {
        return $this->run($bookingId, $this->cleanReason($reason));
    }

    private function cleanReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Cancellation reason is required.');
        }

        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Cancellation reason must not exceed 500 characters.');
        }

        return $reason;
    }

    private function run(int $bookingId, string $reason): Booking
    {
        return DB::transaction(function () use ($bookingId, $reason): Booking {
            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail($bookingId);

            if ((string) $booking->status !== 'Booked') {
                throw new InvalidArgumentException(
                    'Only booked bookings that have not been checked in can be cancelled.',
                );
            }

            $details = BookingDetail::query()
                ->where('booking_id', $booking->booking_id)
                ->lockForUpdate()
                ->get();

            $started = $details->first(fn (BookingDetail $detail): bool => ! in_array(
                (string) $detail->status,
                [...self::OPEN_DETAIL_STATUSES, 'Cancelled'],
                true,
            ));

            if ($started !== null) {
                throw new InvalidArgumentException(
                    'This booking already has a checked-in or checked-out facility and cannot be cancelled.',
                );
            }

            $open = $details->reject(
                fn (BookingDetail $detail): bool => (string) $detail->status === 'Cancelled',
            );

            if ($open->isNotEmpty()) {
                $this->scheduleLock->lockMany($open->pluck('facility_id')->all());
                $this->scheduleBlocks->releaseBookingDetails($open);
            }

            $open->each(fn (BookingDetail $detail) => $detail->update(['status' => 'Cancelled']));

            // Requests nobody has acted on yet are no longer needed.
            $booking->amenityRequests()
                ->whereIn('amenity_request_status', ['Pending', 'Awaiting Payment'])
                ->get()
                ->each(fn ($request) => $request->update(['amenity_request_status' => 'Cancelled']));

            // The amount due is left alone on purpose (same as a cancelled reservation):
            // it keeps the paid-so-far figure readable on the booking and its refund.
            $booking->update([
                'status' => 'Cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => Carbon::today()->toDateString(),
            ]);

            // Proofs still waiting for review are no longer actionable.
            $booking->payments()
                ->whereRaw('LOWER(payment_status) = ?', ['pending'])
                ->get()
                ->each(fn ($payment) => $payment->update(['payment_status' => 'Cancelled']));

            return $booking->fresh(['payments', 'details.facility.facilityType', 'guest']);
        });
    }

    private function guardCashier(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('A logged-in cashier is required to cancel a booking.');
        }

        $user = User::query()->findOrFail($userId);

        if (! $user->role()->where('role_name', 'Cashier')->exists()) {
            throw new InvalidArgumentException('Only a Cashier may cancel bookings.');
        }
    }
}

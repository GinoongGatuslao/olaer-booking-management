<?php

namespace App\Services;

use App\Models\GuestVerificationOtp;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StaffReservationCancellationService
{
    private const OTP_PURPOSE = 'reservation_manage';

    public function cancel(
        int $reservationId,
        string $reason,
        int $cashierUserId,
    ): Reservation {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(
                'Cancellation reason is required.',
            );
        }

        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException(
                'Cancellation reason must not exceed 500 characters.',
            );
        }

        $this->guardCashier($cashierUserId);

        return DB::transaction(function () use (
            $reservationId,
            $reason,
        ): Reservation {
            $reservation = Reservation::query()
                ->with([
                    'payments',
                    'booking',
                    'details.facility.facilityType',
                    'guest.address',
                    'extraGuests',
                ])
                ->lockForUpdate()
                ->findOrFail($reservationId);

            if ((string) $reservation->status !== 'Active') {
                throw new InvalidArgumentException(
                    'Only active reservations can be cancelled.',
                );
            }

            if ($reservation->booking()->exists()) {
                throw new InvalidArgumentException(
                    'This reservation already has a linked booking and cannot be cancelled.',
                );
            }

            $verifiedPaymentExists = $reservation->payments()
                ->whereRaw(
                    'LOWER(payment_status) = ?',
                    ['verified'],
                )
                ->exists();

            if ($verifiedPaymentExists) {
                throw new InvalidArgumentException(
                    'This reservation has a verified payment and cannot be cancelled because the resort does not process refunds.',
                );
            }

            $reservation->update([
                'status' => 'Cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' =>
                    Carbon::today()->toDateString(),
            ]);

            $this->expireManagementOtps(
                (int) $reservation->reservation_id,
            );

            return $reservation->fresh([
                'payments',
                'booking',
                'details.facility.facilityType',
                'guest.address',
                'extraGuests',
            ]);
        });
    }

    private function guardCashier(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException(
                'A logged-in cashier is required to cancel a reservation.',
            );
        }

        $user = User::query()
            ->with('role')
            ->findOrFail($userId);

        if ($user->role?->role_name !== 'Cashier') {
            throw new InvalidArgumentException(
                'Only a Cashier may cancel reservations.',
            );
        }
    }

    private function expireManagementOtps(
        int $reservationId,
    ): void {
        GuestVerificationOtp::query()
            ->where('reservation_id', $reservationId)
            ->where('purpose', self::OTP_PURPOSE)
            ->whereNull('verified_at')
            ->update([
                'expires_at' => Carbon::now()->subMinute(),
            ]);
    }
}

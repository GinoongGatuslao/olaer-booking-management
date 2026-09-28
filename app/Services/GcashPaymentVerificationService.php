<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GcashPaymentVerificationService
{
    public function __construct(
        private readonly GcashReferenceIntegrityService $references,
        private readonly DecimalMoneyService $money,
        private readonly FacilityScheduleLockService $scheduleLock,
        private readonly FacilityScheduleBlockService $scheduleBlocks,
        private readonly TransactionLedgerService $ledger,
    ) {}

    public function verify(
        int $paymentId,
        int $cashierUserId,
    ): Payment {
        $this->guardCashier($cashierUserId);

        return DB::transaction(function () use (
            $paymentId,
            $cashierUserId,
        ): Payment {
            $payment = Payment::query()
                ->with([
                    'booking.details',
                    'reservation.details',
                    'modeOfPayment',
                ])
                ->lockForUpdate()
                ->findOrFail($paymentId);

            $status = strtolower(
                trim((string) $payment->payment_status),
            );

            if ($status === 'verified') {
                $this->guardVerifiedStateIsConsistent($payment);

                return $this->freshPayment($payment);
            }

            if ($status === 'rejected') {
                throw new InvalidArgumentException(
                    'A rejected GCash payment cannot be verified.',
                );
            }

            $this->guardPendingGuestGcashPayment($payment);

            if ($payment->reservation_id !== null) {
                return $this->verifyReservationPayment($payment, $cashierUserId);
            }

            $booking = Booking::query()
                ->with('details')
                ->lockForUpdate()
                ->findOrFail((int) $payment->booking_id);

            if (
                strtolower(trim((string) $booking->status))
                !== 'pending verification'
            ) {
                throw new InvalidArgumentException(
                    'Only bookings pending GCash verification can be verified.',
                );
            }

            $amountPaid = $this->money->normalize((string) $payment->amount_paid);
            $amountDue = $this->money->normalize((string) $booking->amount_due);

            if ($this->money->compare($amountPaid, '0.00') !== 1) {
                throw new InvalidArgumentException(
                    'The submitted GCash payment amount is invalid.',
                );
            }

            if (! $this->money->equals($amountPaid, $amountDue)) {
                throw new InvalidArgumentException(
                    'The submitted GCash amount must exactly match the booking balance.',
                );
            }

            $referenceNumber = $this->references
                ->assertAvailable(
                    (string) $payment->reference_number,
                    (int) $payment->payment_id,
                );

            $payment->update([
                'reference_number' => $referenceNumber,
                'payment_status' => 'Verified',
                'rejection_reason' => null,
                'verified_by_user_id' => $cashierUserId,
                'verified_at' => Carbon::now(),
            ]);

            $booking->update([
                'amount_due' => 0.00,
                'status' => 'Booked',
            ]);

            $booking->details()
                ->where(
                    'status',
                    'Pending Verification',
                )
                ->update([
                    'status' => 'Booked',
                    'user_id' => $cashierUserId,
                ]);

            return $this->freshPayment($payment);
        });
    }

    public function reject(
        int $paymentId,
        int $cashierUserId,
        string $reason,
    ): Payment {
        $this->guardCashier($cashierUserId);

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException(
                'A rejection reason is required.',
            );
        }

        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException(
                'Rejection reason must not exceed 500 characters.',
            );
        }

        return DB::transaction(function () use (
            $paymentId,
            $cashierUserId,
            $reason,
        ): Payment {
            $payment = Payment::query()
                ->with([
                    'booking.details',
                    'reservation.details',
                    'modeOfPayment',
                ])
                ->lockForUpdate()
                ->findOrFail($paymentId);

            $status = strtolower(
                trim((string) $payment->payment_status),
            );

            if ($status === 'rejected') {
                return $this->freshPayment($payment);
            }

            if ($status === 'verified') {
                throw new InvalidArgumentException(
                    'A verified GCash payment cannot be rejected.',
                );
            }

            $this->guardPendingGuestGcashPayment($payment);

            if ($payment->reservation_id !== null) {
                $payment->update([
                    'payment_status' => 'Rejected',
                    'rejection_reason' => $reason,
                    'verified_by_user_id' => $cashierUserId,
                    'verified_at' => Carbon::now(),
                ]);

                return $this->freshPayment($payment);
            }

            $booking = Booking::query()
                ->with('details')
                ->lockForUpdate()
                ->findOrFail((int) $payment->booking_id);

            if (
                strtolower(trim((string) $booking->status))
                !== 'pending verification'
            ) {
                throw new InvalidArgumentException(
                    'Only bookings pending GCash verification can be rejected.',
                );
            }

            $details = BookingDetail::query()
                ->where('booking_id', $booking->booking_id)
                ->orderBy('booking_details_id')
                ->lockForUpdate()
                ->get();

            if ($details->isNotEmpty()) {
                $this->scheduleLock->lockMany(
                    $details->pluck('facility_id')->all(),
                );
            }

            $payment->update([
                'payment_status' => 'Rejected',
                'rejection_reason' => $reason,
                'verified_by_user_id' => $cashierUserId,
                'verified_at' => Carbon::now(),
            ]);

            $booking->update([
                'status' => 'Payment Rejected',
            ]);

            $booking->details()
                ->where(
                    'status',
                    'Pending Verification',
                )
                ->update([
                    'status' => 'Payment Rejected',
                    'user_id' => $cashierUserId,
                ]);

            $this->scheduleBlocks->releaseBookingDetails($details);

            return $this->freshPayment($payment);
        });
    }

    private function guardCashier(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException(
                'A logged-in cashier is required to review GCash payments.',
            );
        }

        $user = User::query()
            ->with('role')
            ->findOrFail($userId);

        if (! $user->role()->where('role_name', 'Cashier')->exists()) {
            throw new InvalidArgumentException(
                'Only a Cashier may verify or reject GCash payments.',
            );
        }
    }

    private function guardPendingGuestGcashPayment(
        Payment $payment,
    ): void {
        $mode = strtolower(
            trim(
                (string) $payment
                    ->modeOfPayment
                    ?->getAttribute('mode_of_payment'),
            ),
        );

        if ($mode !== 'gcash') {
            throw new InvalidArgumentException(
                'Only GCash payments can be reviewed here.',
            );
        }

        if (
            strtolower(
                trim((string) $payment->payment_status),
            ) !== 'pending'
        ) {
            throw new InvalidArgumentException(
                'Only pending GCash payments can be reviewed.',
            );
        }

        if (blank($payment->reference_number)) {
            throw new InvalidArgumentException(
                'This GCash payment has no reference number.',
            );
        }

        if (blank($payment->proof_of_payment_path)) {
            throw new InvalidArgumentException(
                'This GCash payment has no uploaded proof.',
            );
        }

        if (
            ($payment->booking_id === null && $payment->reservation_id === null)
            || ($payment->booking_id !== null && $payment->reservation_id !== null)
        ) {
            throw new InvalidArgumentException(
                'A pending GCash payment must belong to exactly one reservation or booking.',
            );
        }
    }

    private function guardVerifiedStateIsConsistent(
        Payment $payment,
    ): void {
        if ($payment->reservation_id !== null) {
            $reservation = $payment->reservation;

            if (! $reservation) {
                throw new InvalidArgumentException(
                    'This verified payment has no reservation and requires administrative review.',
                );
            }

            return;
        }

        $booking = $payment->booking;

        if (
            ! $booking
            || $this->money->compare((string) $booking->getAttribute('amount_due'), '0.00') === 1
            || strtolower(trim((string) $booking->getAttribute('status')))
                !== 'booked'
        ) {
            throw new InvalidArgumentException(
                'This verified payment has an inconsistent booking state and requires administrative review.',
            );
        }
    }

    private function verifyReservationPayment(
        Payment $payment,
        int $cashierUserId,
    ): Payment {
        $reservation = Reservation::query()
            ->with(['details', 'payments'])
            ->lockForUpdate()
            ->findOrFail((int) $payment->reservation_id);

        if (! in_array((string) $reservation->status, ['Active', 'Paid'], true)) {
            throw new InvalidArgumentException(
                'This reservation can no longer accept a GCash verification.',
            );
        }

        $before = $this->ledger->summaryForReservation($reservation);
        $amountPaid = $this->money->normalize((string) $payment->amount_paid);
        $minimum = $this->money->percentage($before['total'], '0.500000');
        $minimumShortfall = $this->money->maxZero(
            $this->money->subtract($minimum, $before['verified_payments']),
        );

        if ($this->money->compare($amountPaid, '0.00') !== 1) {
            throw new InvalidArgumentException('The submitted GCash payment amount is invalid.');
        }

        if ($this->money->compare($amountPaid, $minimumShortfall) === -1) {
            throw new InvalidArgumentException(
                'Verified reservation payments would remain below the required 50% minimum.',
            );
        }

        if ($this->money->compare($amountPaid, $before['balance']) === 1) {
            throw new InvalidArgumentException(
                'The submitted GCash payment exceeds the reservation balance.',
            );
        }

        $referenceNumber = $this->references->assertAvailable(
            (string) $payment->reference_number,
            (int) $payment->payment_id,
        );

        $payment->update([
            'reference_number' => $referenceNumber,
            'payment_status' => 'Verified',
            'rejection_reason' => null,
            'verified_by_user_id' => $cashierUserId,
            'verified_at' => Carbon::now(),
        ]);

        $after = $this->ledger->summaryForReservation($reservation->fresh(['details', 'payments']));
        $reservation->update([
            'total_price' => $after['total'],
            'amount_due' => $after['balance'],
            'status' => $this->money->compare($after['balance'], '0.00') !== 1
                ? 'Paid'
                : 'Active',
        ]);

        return $this->freshPayment($payment);
    }

    private function freshPayment(Payment $payment): Payment
    {
        return $payment->fresh([
            'booking.guest',
            'booking.details.facility',
            'reservation.guest',
            'reservation.details.facility',
            'modeOfPayment',
            'verifier',
        ]);
    }
}

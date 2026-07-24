<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GcashPaymentVerificationService
{
    public function __construct(
        private readonly GcashReferenceIntegrityService $references,
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

            $amountPaid = round(
                (float) $payment->amount_paid,
                2,
            );
            $amountDue = round(
                (float) $booking->amount_due,
                2,
            );

            if ($amountPaid <= 0) {
                throw new InvalidArgumentException(
                    'The submitted GCash payment amount is invalid.',
                );
            }

            if (abs($amountPaid - $amountDue) > 0.009) {
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

        if ($user->role?->role_name !== 'Cashier') {
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
                    ?->mode_of_payment,
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

        if (! $payment->booking_id) {
            throw new InvalidArgumentException(
                'This verification workflow handles guest booking payments only.',
            );
        }
    }

    private function guardVerifiedStateIsConsistent(
        Payment $payment,
    ): void {
        $booking = $payment->booking;

        if (
            ! $booking
            || round((float) $booking->amount_due, 2) > 0
            || strtolower(trim((string) $booking->status))
                !== 'booked'
        ) {
            throw new InvalidArgumentException(
                'This verified payment has an inconsistent booking state and requires administrative review.',
            );
        }
    }

    private function freshPayment(Payment $payment): Payment
    {
        return $payment->fresh([
            'booking.guest',
            'booking.details.facility',
            'modeOfPayment',
            'verifier',
        ]);
    }
}

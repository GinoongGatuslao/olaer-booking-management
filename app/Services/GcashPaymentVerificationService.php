<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class GcashPaymentVerificationService
{
    public function verify(int $paymentId, int $cashierUserId): Payment
    {
        if ($cashierUserId < 1) {
            throw new InvalidArgumentException('A logged-in cashier is required to verify GCash payments.');
        }

        DB::beginTransaction();

        try {
            $payment = Payment::query()
                ->with(['booking.details'])
                ->lockForUpdate()
                ->findOrFail($paymentId);

            $this->guardPendingGuestGcashPayment($payment);

            $booking = $payment->booking;

            if (! $booking) {
                throw new InvalidArgumentException('Only booking GCash proof verification is supported here.');
            }

            $booking = $booking->newQuery()->lockForUpdate()->with('details')->findOrFail($booking->booking_id);

            $amountPaid = round((float) $payment->amount_paid, 2);
            $amountDue = round((float) $booking->amount_due, 2);
            $newAmountDue = max(round($amountDue - $amountPaid, 2), 0.00);

            $payment->update([
                'payment_status' => 'Verified',
                'verified_by_user_id' => $cashierUserId,
                'verified_at' => Carbon::now(),
            ]);

            $booking->update([
                'amount_due' => $newAmountDue,
                'status' => $newAmountDue <= 0 ? 'Booked' : (string) $booking->status,
            ]);

            if ($newAmountDue <= 0) {
                $booking->details()->where('status', 'Pending Verification')->update([
                    'status' => 'Booked',
                    'user_id' => $cashierUserId,
                ]);
            }

            DB::commit();

            return $payment->fresh(['booking.guest', 'booking.details.facility', 'modeOfPayment', 'verifier']);
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    public function reject(int $paymentId, int $cashierUserId, string $reason): Payment
    {
        if ($cashierUserId < 1) {
            throw new InvalidArgumentException('A logged-in cashier is required to reject GCash payments.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }

        DB::beginTransaction();

        try {
            $payment = Payment::query()
                ->with(['booking.details'])
                ->lockForUpdate()
                ->findOrFail($paymentId);

            $this->guardPendingGuestGcashPayment($payment);

            $booking = $payment->booking;

            if (! $booking) {
                throw new InvalidArgumentException('Only booking GCash proof verification is supported here.');
            }

            $booking = $booking->newQuery()->lockForUpdate()->with('details')->findOrFail($booking->booking_id);

            $payment->update([
                'payment_status' => 'Rejected',
                'verified_by_user_id' => $cashierUserId,
                'verified_at' => Carbon::now(),
            ]);

            $booking->update([
                'status' => 'Payment Rejected',
            ]);

            $booking->details()->where('status', 'Pending Verification')->update([
                'status' => 'Payment Rejected',
                'user_id' => $cashierUserId,
            ]);

            DB::commit();

            return $payment->fresh(['booking.guest', 'booking.details.facility', 'modeOfPayment', 'verifier']);
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    private function guardPendingGuestGcashPayment(Payment $payment): void
    {
        $mode = strtolower(trim((string) optional($payment->modeOfPayment)->mode_of_payment));
        $status = strtolower(trim((string) $payment->payment_status));

        if ($mode !== 'gcash') {
            throw new InvalidArgumentException('Only GCash payments can be verified here.');
        }

        if ($status !== 'pending') {
            throw new InvalidArgumentException('Only pending GCash payments can be verified or rejected.');
        }

        if (blank($payment->proof_of_payment_path)) {
            throw new InvalidArgumentException('This GCash payment has no uploaded proof.');
        }

        if (! $payment->booking_id) {
            throw new InvalidArgumentException('This verification page currently handles guest booking payments only.');
        }
    }
}

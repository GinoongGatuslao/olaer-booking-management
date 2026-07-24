<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\EntranceSlip;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class PaymentWorkflowService
{
    public function __construct(
        private readonly GcashReferenceIntegrityService $gcashReferences,
    ) {}

    public function recordCashierPayment(array $data): Payment
    {
        $targetType = (string) ($data['target_type'] ?? '');
        $targetId = (int) ($data['target_id'] ?? 0);
        $amountPaid = round((float) ($data['amount_paid'] ?? 0), 2);
        $modeOfPaymentId = (int) ($data['mode_of_payment_id'] ?? 0);
        $referenceNumber = trim((string) ($data['reference_number'] ?? ''));
        $cashierUserId = (int) ($data['user_id'] ?? 0);

        if (! in_array($targetType, ['booking', 'reservation', 'entrance_slip'], true)) {
            throw new InvalidArgumentException('Invalid payment target.');
        }

        if ($targetId < 1) {
            throw new InvalidArgumentException('Select a valid payable record.');
        }

        if ($amountPaid <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        $this->guardCashier($cashierUserId);

        $mode = ModeOfPayment::query()->findOrFail($modeOfPaymentId);
        $modeName = strtolower(trim((string) $mode->mode_of_payment));

        if ($modeName === 'gcash' && $referenceNumber === '') {
            throw new InvalidArgumentException('GCash payments require a reference number.');
        }

        DB::beginTransaction();

        try {
            if ($modeName === 'gcash') {
                $referenceNumber = $this->gcashReferences
                    ->assertAvailable($referenceNumber);
            }

            $target = $this->lockTarget($targetType, $targetId);
            $amountDue = round((float) $target->amount_due, 2);

            $this->guardTargetIsPayable($targetType, $target, $amountDue, $amountPaid);

            $newAmountDue = round($amountDue - $amountPaid, 2);

            $paymentPayload = [
                'p_ref_no' => $this->newReference(),
                'booking_id' => $targetType === 'booking' ? $targetId : null,
                'reservation_id' => $targetType === 'reservation' ? $targetId : null,
                'entrance_slip_id' => $targetType === 'entrance_slip' ? $targetId : null,
                'mode_of_payment_id' => $mode->mode_of_payment_id,
                'reference_number' => $referenceNumber !== '' ? $referenceNumber : null,
                'proof_of_payment_path' => null,
                'amount_paid' => $amountPaid,
                'date_paid' => Carbon::today()->toDateString(),
                'user_id' => $cashierUserId,
                'payment_status' => 'Verified',
                'verified_by_user_id' => $cashierUserId,
                'verified_at' => Carbon::now(),
            ];

            $payment = Payment::query()->create($paymentPayload);

            $this->applyPaymentToTarget($targetType, $target, $newAmountDue, $cashierUserId);

            DB::commit();

            return $payment->fresh(['booking.guest', 'reservation.guest', 'entranceSlip.guest', 'modeOfPayment', 'user']);
        } catch (Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    private function guardCashier(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException(
                'A logged-in cashier is required to record payment.',
            );
        }

        $user = User::query()
            ->with('role')
            ->findOrFail($userId);

        if ($user->role?->role_name !== 'Cashier') {
            throw new InvalidArgumentException(
                'Only a Cashier may record payments.',
            );
        }
    }

    private function lockTarget(string $targetType, int $targetId): Model
    {
        return match ($targetType) {
            'booking' => Booking::query()->lockForUpdate()->findOrFail($targetId),
            'reservation' => Reservation::query()->lockForUpdate()->findOrFail($targetId),
            'entrance_slip' => EntranceSlip::query()->lockForUpdate()->findOrFail($targetId),
            default => throw new InvalidArgumentException('Invalid payment target.'),
        };
    }

    private function guardTargetIsPayable(
        string $targetType,
        Model $target,
        float $amountDue,
        float $amountPaid,
    ): void {
        if ($amountDue <= 0) {
            throw new InvalidArgumentException(
                'This record has no unpaid balance.',
            );
        }

        if ($amountPaid > $amountDue) {
            throw new InvalidArgumentException(
                'Payment amount cannot be greater than the unpaid balance.',
            );
        }

        if ($targetType === 'booking') {
            $this->guardBookingIsPayable($target);

            return;
        }

        if ($targetType === 'reservation') {
            $this->guardReservationIsPayable($target);

            return;
        }

        if ($targetType === 'entrance_slip') {
            $this->guardEntranceSlipIsPayable(
                $target,
                $amountDue,
                $amountPaid,
            );
        }
    }

    private function guardBookingIsPayable(Model $booking): void
    {
        $status = (string) $booking->status;

        $payableStatuses = [
            'Booked',
            'Checked-in',
            'Partially Checked-in',
            'Partially Checked-out',
        ];

        if (! in_array($status, $payableStatuses, true)) {
            throw new InvalidArgumentException(
                'This booking can no longer accept payments.',
            );
        }
    }

    private function guardReservationIsPayable(Model $reservation): void
    {
        $status = (string) $reservation->status;

        if ($status !== 'Active') {
            throw new InvalidArgumentException(
                'This reservation can no longer accept payments.',
            );
        }
    }

    private function guardEntranceSlipIsPayable(
        Model $entranceSlip,
        float $amountDue,
        float $amountPaid,
    ): void {
        $status = (string) $entranceSlip->status;

        if ($status === 'Paid') {
            throw new InvalidArgumentException(
                'This entrance slip is already paid.',
            );
        }

        if (abs($amountPaid - $amountDue) > 0.009) {
            throw new InvalidArgumentException(
                'Entrance slips must be paid in full.',
            );
        }
    }

    private function applyPaymentToTarget(string $targetType, Model $target, float $newAmountDue, int $cashierUserId): void
    {
        if ($targetType === 'booking') {
            $target->update([
                'amount_due' => $newAmountDue,
            ]);

            if ($newAmountDue <= 0) {
                app(AmenityRequestWorkflowService::class)->releasePaidRequestsForBooking((int) $target->booking_id);
            }

            return;
        }

        if ($targetType === 'reservation') {
            $target->update([
                'amount_due' => $newAmountDue,
                'status' => $newAmountDue <= 0 ? 'Paid' : $target->status,
            ]);

            return;
        }

        if ($targetType === 'entrance_slip') {
            $target->update([
                'amount_due' => $newAmountDue,
                'handled_by_user_id' => $cashierUserId,
                'status' => $newAmountDue <= 0 ? 'Paid' : 'Unpaid',
            ]);
        }
    }

    private function newReference(): string
    {
        do {
            $reference = 'P' . now()->format('ymdHis') . strtoupper(Str::random(4));
        } while (Payment::query()->where('p_ref_no', $reference)->exists());

        return $reference;
    }
}

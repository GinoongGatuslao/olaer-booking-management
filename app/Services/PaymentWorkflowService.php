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
        private readonly DecimalMoneyService $money,
        private readonly TransactionLedgerService $ledger,
    ) {}

    /** @param array<string, mixed> $data */
    public function recordCashierPayment(array $data): Payment
    {
        $targetType = (string) ($data['target_type'] ?? '');
        $targetId = (int) ($data['target_id'] ?? 0);
        $amountPaid = $this->money->normalize($data['amount_paid'] ?? '');
        $modeOfPaymentId = (int) (
            $data['mode_of_payment_id'] ?? 0
        );
        $referenceNumber = trim(
            (string) ($data['reference_number'] ?? ''),
        );
        $cashierUserId = (int) ($data['user_id'] ?? 0);

        if (
            ! in_array(
                $targetType,
                ['booking', 'reservation', 'entrance_slip'],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid payment target.',
            );
        }

        if ($targetId < 1) {
            throw new InvalidArgumentException(
                'Select a valid payable record.',
            );
        }

        if ($this->money->compare($amountPaid, '0.00') !== 1) {
            throw new InvalidArgumentException(
                'Payment amount must be greater than zero.',
            );
        }

        $this->guardCashier($cashierUserId);

        $mode = ModeOfPayment::query()
            ->findOrFail($modeOfPaymentId);

        $modeName = strtolower(
            trim((string) $mode->mode_of_payment),
        );

        if (
            $modeName === 'gcash'
            && $referenceNumber === ''
        ) {
            throw new InvalidArgumentException(
                'GCash payments require a reference number.',
            );
        }

        DB::beginTransaction();

        try {
            if ($modeName === 'gcash') {
                $referenceNumber = $this->gcashReferences
                    ->assertAvailable($referenceNumber);
            }

            $target = $this->lockTarget(
                $targetType,
                $targetId,
            );

            $amountDue = $this->ledger->balanceFor($targetType, $targetId);

            if (! $this->money->equals((string) $target->getAttribute('amount_due'), $amountDue)) {
                $target->update(['amount_due' => $amountDue]);
            }

            $this->guardTargetIsPayable(
                $targetType,
                $target,
                $amountDue,
                $amountPaid,
            );

            $newAmountDue = $this->money->subtract($amountDue, $amountPaid);

            $paymentPayload = [
                'p_ref_no' => $this->newReference(),
                'booking_id' => $targetType === 'booking'
                        ? $targetId
                        : null,
                'reservation_id' => $targetType === 'reservation'
                        ? $targetId
                        : null,
                'entrance_slip_id' => $targetType === 'entrance_slip'
                        ? $targetId
                        : null,
                'mode_of_payment_id' => $mode->mode_of_payment_id,
                'reference_number' => $referenceNumber !== ''
                        ? $referenceNumber
                        : null,
                'proof_of_payment_path' => null,
                'amount_paid' => $amountPaid,
                'date_paid' => Carbon::today()->toDateString(),
                'user_id' => $cashierUserId,
                'payment_status' => 'Verified',
                'verified_by_user_id' => $cashierUserId,
                'verified_at' => Carbon::now(),
            ];

            $payment = Payment::query()
                ->create($paymentPayload);

            $this->applyPaymentToTarget(
                $targetType,
                $target,
                $newAmountDue,
                $cashierUserId,
            );

            DB::commit();

            return $payment->fresh([
                'booking.guest',
                'reservation.guest',
                'entranceSlip.guest',
                'modeOfPayment',
                'user',
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
                'A logged-in cashier is required to record payment.',
            );
        }

        $user = User::query()
            ->with('role')
            ->findOrFail($userId);

        if (! $user->role()->where('role_name', 'Cashier')->exists()) {
            throw new InvalidArgumentException(
                'Only a Cashier may record payments.',
            );
        }
    }

    private function lockTarget(
        string $targetType,
        int $targetId,
    ): Model {
        return match ($targetType) {
            'booking' => Booking::query()
                ->lockForUpdate()
                ->findOrFail($targetId),
            'reservation' => Reservation::query()
                ->lockForUpdate()
                ->findOrFail($targetId),
            'entrance_slip' => EntranceSlip::query()
                ->lockForUpdate()
                ->findOrFail($targetId),
            default => throw new InvalidArgumentException(
                'Invalid payment target.',
            ),
        };
    }

    private function guardTargetIsPayable(
        string $targetType,
        Model $target,
        string $amountDue,
        string $amountPaid,
    ): void {
        if ($this->money->compare($amountDue, '0.00') !== 1) {
            throw new InvalidArgumentException(
                'This record has no unpaid balance.',
            );
        }

        if ($this->money->compare($amountPaid, $amountDue) === 1) {
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

    private function guardBookingIsPayable(
        Model $booking,
    ): void {
        $payableStatuses = [
            'Booked',
            'Checked-in',
            'Partially Checked-in',
            'Partially Checked-out',
        ];

        if (
            ! in_array(
                (string) $booking->getAttribute('status'),
                $payableStatuses,
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'This booking can no longer accept payments.',
            );
        }
    }

    private function guardReservationIsPayable(
        Model $reservation,
    ): void {
        if ((string) $reservation->getAttribute('status') !== 'Active') {
            throw new InvalidArgumentException(
                'This reservation can no longer accept payments.',
            );
        }
    }

    private function guardEntranceSlipIsPayable(
        Model $target,
        string $amountDue,
        string $amountPaid,
    ): void {
        if (! $target instanceof EntranceSlip) {
            throw new InvalidArgumentException(
                'Invalid entrance slip payment target.',
            );
        }

        if ((string) $target->getAttribute('status') === 'Paid') {
            throw new InvalidArgumentException(
                'This entrance slip is already paid.',
            );
        }

        if ((string) $target->getAttribute('status') !== 'Unpaid') {
            throw new InvalidArgumentException(
                'This entrance slip cannot accept payment in its current status.',
            );
        }

        $totalPrice = $this->money->normalize((string) $target->getAttribute('total_price'));

        if (
            $this->money->compare($totalPrice, '0.00') !== 1
            || ! $this->money->equals($amountDue, $totalPrice)
        ) {
            throw new InvalidArgumentException(
                'This entrance slip has an inconsistent balance and must be reviewed before payment.',
            );
        }

        $verifiedPaymentExists = $target->payments()
            ->whereRaw(
                'LOWER(payment_status) = ?',
                ['verified'],
            )
            ->exists();

        if ($verifiedPaymentExists) {
            throw new InvalidArgumentException(
                'This entrance slip already has a verified payment.',
            );
        }

        if (! $this->money->equals($amountPaid, $amountDue)) {
            throw new InvalidArgumentException(
                'Entrance slips must be paid in full.',
            );
        }
    }

    private function applyPaymentToTarget(
        string $targetType,
        Model $target,
        string $newAmountDue,
        int $cashierUserId,
    ): void {
        if ($targetType === 'booking') {
            $target->update([
                'amount_due' => $newAmountDue,
            ]);

            return;
        }

        if ($targetType === 'reservation') {
            $target->update([
                'amount_due' => $newAmountDue,
                'status' => $this->money->compare($newAmountDue, '0.00') !== 1
                        ? 'Paid'
                        : $target->getAttribute('status'),
            ]);

            return;
        }

        if ($targetType === 'entrance_slip') {
            $target->update([
                'amount_due' => 0.00,
                'handled_by_user_id' => $cashierUserId,
                'status' => 'Paid',
            ]);
        }
    }

    private function newReference(): string
    {
        do {
            $reference = 'P'
                .now()->format('ymdHis')
                .strtoupper(Str::random(4));
        } while (
            Payment::query()
                ->where('p_ref_no', $reference)
                ->exists()
        );

        return $reference;
    }
}

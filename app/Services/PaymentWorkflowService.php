<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\EntranceSlip;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Support\CashTender;
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
        private readonly ReservationToBookingWorkflowService $reservationConversion,
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

        // Cash payments need the cash received; the change is worked out here.
        $cashPaid = CashTender::resolve($mode, $amountPaid, $data['cash_paid'] ?? null);

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

            $ledger = $this->ledger->summaryFor($targetType, $targetId);
            $amountDue = $ledger['balance'];

            $repairs = [];
            if ($target->getAttribute('total_price') !== null
                && ! $this->money->equals((string) $target->getAttribute('total_price'), $ledger['total'])) {
                $repairs['total_price'] = $ledger['total'];
            }
            if (! $this->money->equals((string) $target->getAttribute('amount_due'), $amountDue)) {
                $repairs['amount_due'] = $amountDue;
            }
            if ($repairs !== []) {
                $target->update($repairs);
            }

            $this->guardTargetIsPayable(
                $targetType,
                $target,
                $amountDue,
                $amountPaid,
            );

            // Reservations only: the first payment(s) must reach the 50% down payment.
            if ($targetType === 'reservation') {
                $this->guardReservationDownPayment($ledger, $amountPaid);
            }

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
                'cash_paid' => $cashPaid,
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

            // A reservation that is now fully paid becomes a booking automatically.
            if (
                $targetType === 'reservation'
                && $this->money->compare($newAmountDue, '0.00') !== 1
            ) {
                $this->reservationConversion->convertIfFullyPaid(
                    $targetId,
                    $cashierUserId,
                );
            }

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

    /** A reservation needs a down payment of this share of its total before it is held. */
    public const RESERVATION_DOWN_PAYMENT_RATE = '0.50';

    /**
     * The least a cashier may record right now for a reservation, or null for other targets.
     * Until 50% of the total is paid, a payment must bring the paid amount up to 50%.
     * Once 50% is paid, there is no minimum (any amount up to the balance is accepted).
     *
     * @return array{total: string, down_payment: string, paid: string, minimum: string}|null
     */
    public function reservationMinimumPayment(string $targetType, int $targetId): ?array
    {
        if ($targetType !== 'reservation') {
            return null;
        }

        return $this->downPaymentFromLedger($this->ledger->summaryFor('reservation', $targetId));
    }

    /**
     * @param  array{total: string, applied_credits: string, verified_payments: string, balance: string}  $ledger
     * @return array{total: string, down_payment: string, paid: string, minimum: string}
     */
    private function downPaymentFromLedger(array $ledger): array
    {
        $downPayment = $this->money->percentage($ledger['total'], self::RESERVATION_DOWN_PAYMENT_RATE);
        $paid = $this->money->add($ledger['verified_payments'], $ledger['applied_credits']);
        $minimum = $this->money->maxZero($this->money->subtract($downPayment, $paid));

        // Never ask for more than what is still owed.
        if ($this->money->compare($minimum, $ledger['balance']) === 1) {
            $minimum = $this->money->normalize($ledger['balance']);
        }

        return [
            'total' => $this->money->normalize($ledger['total']),
            'down_payment' => $downPayment,
            'paid' => $paid,
            'minimum' => $minimum,
        ];
    }

    /** @param  array{total: string, applied_credits: string, verified_payments: string, balance: string}  $ledger */
    private function guardReservationDownPayment(array $ledger, string $amountPaid): void
    {
        $down = $this->downPaymentFromLedger($ledger);

        if ($this->money->compare($amountPaid, $down['minimum']) !== -1) {
            return;
        }

        throw new InvalidArgumentException(
            'A reservation needs a 50% down payment (₱'.number_format((float) $down['down_payment'], 2)
            .' of ₱'.number_format((float) $down['total'], 2).'). '
            .($this->money->compare($down['paid'], '0.00') === 1
                ? 'The guest has paid ₱'.number_format((float) $down['paid'], 2).' so far, so this payment must be at least '
                : 'The first payment must be at least ')
            .'₱'.number_format((float) $down['minimum'], 2).'. After that, any amount is accepted.',
        );
    }

    private function guardTargetIsPayable(
        string $targetType,
        Model $target,
        string $amountDue,
        string $amountPaid,
    ): void {
        if ($targetType === 'booking') {
            $this->guardBookingIsPayable($target);
        } elseif ($targetType === 'reservation') {
            $this->guardReservationIsPayable($target);
        } elseif ($targetType === 'entrance_slip') {
            $this->guardEntranceSlipIsPayable($target);
        }

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
    ): void {
        if (! $target instanceof EntranceSlip) {
            throw new InvalidArgumentException(
                'Invalid entrance slip payment target.',
            );
        }

        if ($target->isClosed()) {
            throw new InvalidArgumentException(
                'This entrance slip is closed. It can only be viewed.',
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

        // Partial payments are accepted: the slip stays Unpaid (with a smaller
        // balance) until the balance reaches zero. The shared checks in
        // guardTargetIsPayable() reject a zero balance and overpayment.
        $totalPrice = $this->money->normalize((string) $target->getAttribute('total_price'));

        if ($this->money->compare($totalPrice, '0.00') !== 1) {
            throw new InvalidArgumentException(
                'This entrance slip has an inconsistent balance and must be reviewed before payment.',
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
                'amount_due' => $newAmountDue,
                'handled_by_user_id' => $cashierUserId,
                'status' => $this->money->compare($newAmountDue, '0.00') !== 1
                    ? 'Paid'
                    : $target->getAttribute('status'),
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
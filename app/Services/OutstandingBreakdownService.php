<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\EntranceSlip;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\TransactionAdjustment;
use InvalidArgumentException;

/**
 * What still makes up the unpaid balance of a transaction.
 *
 * Payments are lump sums, so they are applied to the charges in billing order
 * (facility first, then delivered amenities, fines, adjustments). A charge that
 * is already fully covered is left out; only what is still owing is listed.
 */
class OutstandingBreakdownService
{
    public function __construct(
        private readonly BillingStatementService $billing,
        private readonly TransactionLedgerService $ledger,
        private readonly DecimalMoneyService $money,
    ) {}

    /**
     * @return array{balance: string, lines: list<array{group: string, label: string, detail: string, amount: string, paid: string, remaining: string}>}
     */
    public function for(string $targetType, int $targetId): array
    {
        [$charges, $creditAdjustments] = $this->chargesWithAdjustments($targetType, $targetId);

        $balance = $this->ledger->summaryFor($targetType, $targetId)['balance'];
        $chargeTotal = $this->money->add(...array_column($charges, 'amount') ?: ['0.00']);

        // Everything already settled (payments, credits, credit adjustments).
        $settled = $this->money->maxZero(
            $this->money->subtract(
                $this->money->subtract($chargeTotal, $creditAdjustments),
                $balance,
            ),
        );
        $settled = $this->money->add($settled, $creditAdjustments);

        $lines = [];

        foreach ($charges as $charge) {
            $covered = $this->money->compare($settled, $charge['amount']) >= 0
                ? $charge['amount']
                : $settled;
            $settled = $this->money->subtract($settled, $covered);
            $remaining = $this->money->subtract($charge['amount'], $covered);

            if ($this->money->compare($remaining, '0.00') === 1) {
                $lines[] = [...$charge, 'paid' => $covered, 'remaining' => $remaining];
            }
        }

        // Balance the ledger reports that no listed charge explains.
        $listed = $this->money->add(...array_column($lines, 'remaining') ?: ['0.00']);
        $unexplained = $this->money->subtract($balance, $listed);

        if ($this->money->compare($unexplained, '0.00') === 1) {
            $lines[] = [
                'group' => 'Other',
                'label' => 'Other charges (not itemised)',
                'detail' => '',
                'amount' => $unexplained,
                'paid' => '0.00',
                'remaining' => $unexplained,
            ];
        }

        return ['balance' => $balance, 'lines' => $lines];
    }

    /**
     * The itemised charges of a transaction (billing order) and the total of
     * credit adjustments that reduce what is owed.
     *
     * @return array{0: list<array{group: string, label: string, detail: string, amount: string}>, 1: string}
     */
    private function chargesWithAdjustments(string $targetType, int $targetId): array
    {
        $charges = match ($targetType) {
            'booking' => $this->bookingCharges($targetId),
            'reservation' => $this->reservationCharges($targetId),
            'entrance_slip' => $this->entranceSlipCharges($targetId),
            default => throw new InvalidArgumentException('Unsupported transaction type.'),
        };

        $column = $targetType.'_id';
        $adjustments = TransactionAdjustment::query()->where($column, $targetId)->get();
        $creditAdjustments = '0.00';

        foreach ($adjustments as $adjustment) {
            if (strcasecmp((string) $adjustment->direction, 'Debit') === 0) {
                $charges[] = [
                    'group' => 'Adjustments',
                    'label' => (string) ($adjustment->reason ?: 'Adjustment'),
                    'detail' => '',
                    'amount' => $this->money->normalize((string) $adjustment->amount),
                ];
            } else {
                $creditAdjustments = $this->money->add($creditAdjustments, (string) $adjustment->amount);
            }
        }

        return [$charges, $creditAdjustments];
    }

    /**
     * What one verified payment covered, applying the transaction's payments in
     * the order they were made against its charges (facility, delivered
     * amenities, fines, adjustments).
     *
     * @return array{lines: list<array<string, string>>, unapplied: string, balance_after: string}|null
     */
    public function forPayment(Payment $payment): ?array
    {
        if ($payment->booking_id !== null) {
            $targetType = 'booking';
            $targetId = (int) $payment->booking_id;
        } elseif ($payment->reservation_id !== null) {
            $targetType = 'reservation';
            $targetId = (int) $payment->reservation_id;
        } elseif ($payment->entrance_slip_id !== null) {
            $targetType = 'entrance_slip';
            $targetId = (int) $payment->entrance_slip_id;
        } else {
            return null;
        }

        [$charges, $creditAdjustments] = $this->chargesWithAdjustments($targetType, $targetId);

        // Verified payments of this transaction, oldest first. A booking also owns
        // the payment taken on the reservation it was converted from.
        $payments = Payment::query()
            ->whereRaw('LOWER(payment_status) = ?', ['verified'])
            ->where(function ($query) use ($targetType, $targetId): void {
                if ($targetType === 'booking') {
                    $reservationId = Booking::query()->whereKey($targetId)->first()?->reservation?->reservation_id;

                    $query->where('booking_id', $targetId);

                    if ($reservationId !== null) {
                        $query->orWhere('reservation_id', $reservationId);
                    }

                    return;
                }

                $query->where($targetType.'_id', $targetId);
            })
            ->orderBy('payment_id')
            ->get(['payment_id', 'amount_paid']);

        $before = '0.00';

        foreach ($payments as $other) {
            if ((int) $other->payment_id >= (int) $payment->payment_id) {
                break;
            }

            $before = $this->money->add($before, (string) $other->amount_paid);
        }

        $thisPayment = $this->money->normalize((string) $payment->amount_paid);
        $lines = [];
        $remainingPayment = $thisPayment;
        $alreadyCovered = $before;

        foreach ($charges as $charge) {
            // First use up what earlier payments already covered...
            $coveredEarlier = $this->money->compare($alreadyCovered, $charge['amount']) >= 0
                ? $charge['amount']
                : $alreadyCovered;
            $alreadyCovered = $this->money->subtract($alreadyCovered, $coveredEarlier);
            $open = $this->money->subtract($charge['amount'], $coveredEarlier);

            if ($this->money->compare($open, '0.00') !== 1 || $this->money->compare($remainingPayment, '0.00') !== 1) {
                continue;
            }

            // ...then apply this payment to what is still open.
            $applied = $this->money->compare($remainingPayment, $open) >= 0 ? $open : $remainingPayment;
            $remainingPayment = $this->money->subtract($remainingPayment, $applied);

            $lines[] = [
                'group' => $charge['group'],
                'label' => $charge['label'],
                'detail' => $charge['detail'],
                'charge' => $charge['amount'],
                'applied' => $applied,
                'remaining' => $this->money->subtract($open, $applied),
            ];
        }

        $chargeTotal = $this->money->add(...array_column($charges, 'amount') ?: ['0.00']);
        $owed = $this->money->maxZero($this->money->subtract($chargeTotal, $creditAdjustments));
        $paidThrough = $this->money->add($before, $thisPayment);

        return [
            'lines' => $lines,
            'unapplied' => $remainingPayment,
            'balance_after' => $this->money->maxZero($this->money->subtract($owed, $paidThrough)),
        ];
    }

    /** @return list<array{group: string, label: string, detail: string, amount: string}> */
    private function bookingCharges(int $bookingId): array
    {
        $statement = $this->billing->statementForBooking($bookingId);
        $charges = [];

        foreach ($statement['facility_lines'] as $line) {
            if (strcasecmp((string) $line['status'], 'Cancelled') === 0) {
                continue;
            }

            $charges[] = [
                'group' => 'Facility',
                'label' => (string) $line['facility'],
                'detail' => trim($line['rate_type'].' · '.$line['check_in_date'].' to '.$line['check_out_date'], ' ·'),
                'amount' => $this->money->normalize((string) $line['line_total']),
            ];
        }

        foreach ($statement['amenity_lines'] as $line) {
            // Only amenities that were actually delivered are billed here.
            if (strcasecmp((string) $line['request_status'], 'Delivered') !== 0) {
                continue;
            }

            $charges[] = [
                'group' => 'Amenity',
                'label' => $line['amenity'].' × '.$line['quantity'],
                'detail' => implode(' · ', array_filter([
                    'Request AR-'.$line['request_id'],
                    (string) $line['facility'],
                    '₱'.number_format((float) $line['unit_price'], 2).' each',
                    ! empty($line['delivered_at']) ? 'Delivered '.$line['delivered_at'] : null,
                ])),
                'amount' => $this->money->normalize((string) $line['line_total']),
            ];
        }

        foreach ($statement['fine_lines'] as $line) {
            $charges[] = [
                'group' => 'Fine',
                'label' => $line['description'].' × '.$line['quantity'],
                'detail' => (string) $line['facility'],
                'amount' => $this->money->normalize((string) $line['total_charge']),
            ];
        }

        return $charges;
    }

    /** @return list<array{group: string, label: string, detail: string, amount: string}> */
    private function reservationCharges(int $reservationId): array
    {
        $reservation = Reservation::query()
            ->with('details.facility')
            ->findOrFail($reservationId);

        $charges = [];

        foreach ($reservation->details as $detail) {
            if (strcasecmp((string) $detail->status, 'Cancelled') === 0) {
                continue;
            }

            $charges[] = [
                'group' => 'Facility',
                'label' => (string) ($detail->facility?->facility_name ?? 'Facility'),
                'detail' => trim($detail->rate_type.' · '.optional($detail->check_in_date)->toDateString().' to '.optional($detail->check_out_date)->toDateString(), ' ·'),
                'amount' => $this->money->normalize((string) ($detail->line_total ?? '0.00')),
            ];
        }

        return $charges;
    }

    /** @return list<array{group: string, label: string, detail: string, amount: string}> */
    private function entranceSlipCharges(int $slipId): array
    {
        $slip = EntranceSlip::query()
            ->with('details.entranceFee')
            ->findOrFail($slipId);

        $charges = [];

        foreach ($slip->details as $detail) {
            $gross = $this->money->multiply((string) ($detail->unit_rate_snapshot ?? '0.00'), (int) $detail->guest_quantity);

            $charges[] = [
                'group' => 'Entrance',
                'label' => ($detail->entranceFee?->entrance_fee_name ?? 'Entrance fee').' × '.$detail->guest_quantity,
                'detail' => '',
                'amount' => $this->money->normalize((string) ($detail->line_total_snapshot ?? $gross)),
            ];
        }

        return $charges;
    }
}

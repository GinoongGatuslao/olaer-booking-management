<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\EntranceSlip;
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

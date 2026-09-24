<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\EntranceSlip;
use App\Models\Reservation;
use App\Models\TransactionAdjustment;
use App\Models\TransactionCredit;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class TransactionLedgerService
{
    public function __construct(private readonly DecimalMoneyService $money) {}

    /**
     * @return array{charges: string, discounts: string, applied_credits: string, verified_payments: string, balance: string}
     */
    public function summaryForReservation(Reservation|int $reservation): array
    {
        $reservation = $reservation instanceof Reservation
            ? $reservation
            : Reservation::query()->findOrFail($reservation);

        $reservation->loadMissing(['details', 'payments']);

        $charges = '0.00';
        $discounts = '0.00';

        foreach ($reservation->details as $detail) {
            $base = $detail->base_price ?? $detail->line_total ?? '0.00';
            $extra = $detail->extra_guest_fee ?? '0.00';
            $discount = $detail->discount_amount ?? '0.00';

            $charges = $this->money->add($charges, (string) $base, (string) $extra);
            $discounts = $this->money->add($discounts, (string) $discount);
        }

        [$debits, $adjustmentCredits] = $this->adjustments('reservation_id', (int) $reservation->reservation_id);
        $charges = $this->money->add($charges, $debits);
        $discounts = $this->money->add($discounts, $adjustmentCredits);

        return $this->finish(
            $charges,
            $discounts,
            $this->appliedCredits('reservation_id', (int) $reservation->reservation_id),
            $this->verifiedPayments($reservation->payments),
        );
    }

    /**
     * @return array{charges: string, discounts: string, applied_credits: string, verified_payments: string, balance: string}
     */
    public function summaryForBooking(Booking|int $booking): array
    {
        $booking = $booking instanceof Booking
            ? $booking
            : Booking::query()->findOrFail($booking);

        $booking->loadMissing([
            'details',
            'amenityRequests.details',
            'guestFines',
            'payments',
            'reservation.payments',
        ]);

        $charges = '0.00';
        $discounts = '0.00';

        foreach ($booking->details as $detail) {
            $base = $detail->base_price ?? $detail->line_total ?? '0.00';
            $extra = $detail->extra_guest_fee ?? '0.00';
            $discount = $detail->discount_amount ?? '0.00';

            $charges = $this->money->add($charges, (string) $base, (string) $extra);
            $discounts = $this->money->add($discounts, (string) $discount);
        }

        foreach ($booking->amenityRequests as $request) {
            if (strcasecmp((string) $request->amenity_request_status, 'Cancelled') === 0) {
                continue;
            }

            foreach ($request->details as $detail) {
                $lineTotal = $detail->line_total;

                if ($lineTotal === null) {
                    $lineTotal = $this->money->multiply(
                        (string) ($detail->unit_price ?? '0.00'),
                        (int) $detail->amenity_quantity,
                    );
                }

                $charges = $this->money->add($charges, (string) $lineTotal);
            }
        }

        foreach ($booking->guestFines as $fine) {
            $charges = $this->money->add($charges, (string) $fine->total_charge);
        }

        [$debits, $adjustmentCredits] = $this->adjustments('booking_id', (int) $booking->booking_id);
        $charges = $this->money->add($charges, $debits);
        $discounts = $this->money->add($discounts, $adjustmentCredits);

        $payments = $this->verifiedPayments($booking->payments);

        if ($booking->reservation !== null) {
            $payments = $this->money->add(
                $payments,
                $this->verifiedPayments($booking->reservation->payments),
            );
        }

        return $this->finish(
            $charges,
            $discounts,
            $this->appliedCredits('booking_id', (int) $booking->booking_id),
            $payments,
        );
    }

    /**
     * @return array{charges: string, discounts: string, applied_credits: string, verified_payments: string, balance: string}
     */
    public function summaryForEntranceSlip(EntranceSlip|int $entranceSlip): array
    {
        $entranceSlip = $entranceSlip instanceof EntranceSlip
            ? $entranceSlip
            : EntranceSlip::query()->findOrFail($entranceSlip);

        $entranceSlip->loadMissing(['details', 'payments']);

        $charges = '0.00';
        $discounts = '0.00';

        foreach ($entranceSlip->details as $detail) {
            $unitRate = (string) ($detail->unit_rate_snapshot ?? '0.00');
            $quantity = (int) $detail->guest_quantity;
            $gross = $this->money->multiply($unitRate, $quantity);
            $net = (string) ($detail->line_total_snapshot ?? $gross);

            $charges = $this->money->add($charges, $gross);
            $discounts = $this->money->add(
                $discounts,
                $this->money->maxZero($this->money->subtract($gross, $net)),
            );
        }

        [$debits, $adjustmentCredits] = $this->adjustments('entrance_slip_id', (int) $entranceSlip->entrance_slip_id);
        $charges = $this->money->add($charges, $debits);
        $discounts = $this->money->add($discounts, $adjustmentCredits);

        return $this->finish(
            $charges,
            $discounts,
            '0.00',
            $this->verifiedPayments($entranceSlip->payments),
        );
    }

    /** @return numeric-string */
    public function balanceFor(string $targetType, Model|int $target): string
    {
        $summary = match ($targetType) {
            'booking' => $this->summaryForBooking($target instanceof Booking ? $target : (int) $target),
            'reservation' => $this->summaryForReservation($target instanceof Reservation ? $target : (int) $target),
            'entrance_slip' => $this->summaryForEntranceSlip($target instanceof EntranceSlip ? $target : (int) $target),
            default => throw new InvalidArgumentException('Unsupported transaction ledger target.'),
        };

        return $summary['balance'];
    }

    /** @return array{0: string, 1: string} */
    private function adjustments(string $parentColumn, int $parentId): array
    {
        $adjustments = TransactionAdjustment::query()
            ->where($parentColumn, $parentId)
            ->get(['direction', 'amount']);

        $debits = '0.00';
        $credits = '0.00';

        foreach ($adjustments as $adjustment) {
            if (strcasecmp((string) $adjustment->direction, 'Debit') === 0) {
                $debits = $this->money->add($debits, (string) $adjustment->amount);
            } elseif (strcasecmp((string) $adjustment->direction, 'Credit') === 0) {
                $credits = $this->money->add($credits, (string) $adjustment->amount);
            }
        }

        return [$debits, $credits];
    }

    /** @return numeric-string */
    private function appliedCredits(string $parentColumn, int $parentId): string
    {
        $amounts = TransactionCredit::query()
            ->where($parentColumn, $parentId)
            ->with('allocations')
            ->get()
            ->flatMap(fn (TransactionCredit $credit) => $credit->allocations)
            ->pluck('amount')
            ->map(fn (mixed $amount): string => (string) $amount)
            ->all();

        return $this->money->add(...$amounts);
    }

    /** @return numeric-string */
    private function verifiedPayments(iterable $payments): string
    {
        $amounts = collect($payments)
            ->filter(fn ($payment): bool => strcasecmp((string) $payment->payment_status, 'Verified') === 0)
            ->pluck('amount_paid')
            ->map(fn (mixed $amount): string => (string) $amount)
            ->all();

        return $this->money->add(...$amounts);
    }

    /**
     * @return array{charges: string, discounts: string, applied_credits: string, verified_payments: string, balance: string}
     */
    private function finish(
        string $charges,
        string $discounts,
        string $appliedCredits,
        string $verifiedPayments,
    ): array {
        $balance = $this->money->subtract($charges, $discounts);
        $balance = $this->money->subtract($balance, $appliedCredits);
        $balance = $this->money->subtract($balance, $verifiedPayments);

        return [
            'charges' => $this->money->normalize($charges),
            'discounts' => $this->money->normalize($discounts),
            'applied_credits' => $this->money->normalize($appliedCredits),
            'verified_payments' => $this->money->normalize($verifiedPayments),
            'balance' => $this->money->maxZero($balance),
        ];
    }
}

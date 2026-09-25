<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Reservation;
use App\Models\TransactionCredit;
use App\Models\TransactionCreditAllocation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransactionCreditService
{
    public function __construct(private readonly DecimalMoneyService $money) {}

    public function createForBooking(
        Booking $booking,
        string $sourceType,
        ?int $sourceId,
        string $amount,
        string $reason,
        ?int $userId,
    ): TransactionCredit {
        return $this->create(
            bookingId: (int) $booking->booking_id,
            reservationId: null,
            sourceType: $sourceType,
            sourceId: $sourceId,
            amount: $amount,
            reason: $reason,
            userId: $userId,
        );
    }

    public function createForReservation(
        Reservation $reservation,
        string $sourceType,
        ?int $sourceId,
        string $amount,
        string $reason,
        ?int $userId,
    ): TransactionCredit {
        return $this->create(
            bookingId: null,
            reservationId: (int) $reservation->reservation_id,
            sourceType: $sourceType,
            sourceId: $sourceId,
            amount: $amount,
            reason: $reason,
            userId: $userId,
        );
    }

    /**
     * Apply available credit only inside the same parent transaction.
     *
     * @return numeric-string amount actually applied
     */
    public function applyToBooking(
        int $bookingId,
        string $targetType,
        ?int $targetId,
        string $requestedAmount,
        int $userId,
    ): string {
        return DB::transaction(function () use (
            $bookingId,
            $targetType,
            $targetId,
            $requestedAmount,
            $userId,
        ): string {
            Booking::query()->lockForUpdate()->findOrFail($bookingId);
            $remaining = $this->money->normalize($requestedAmount);

            if ($this->money->compare($remaining, '0.00') !== 1) {
                throw new InvalidArgumentException('Credit application amount must be greater than zero.');
            }

            $applied = '0.00';
            $credits = TransactionCredit::query()
                ->where('booking_id', $bookingId)
                ->where('status', 'Available')
                ->where('remaining_amount', '>', 0)
                ->orderBy('transaction_credit_id')
                ->lockForUpdate()
                ->get();

            foreach ($credits as $credit) {
                if ($this->money->compare($remaining, '0.00') !== 1) {
                    break;
                }

                $available = $this->money->normalize((string) $credit->remaining_amount);
                $take = $this->money->compare($available, $remaining) === 1
                    ? $remaining
                    : $available;

                TransactionCreditAllocation::query()->create([
                    'transaction_credit_id' => $credit->transaction_credit_id,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'amount' => $take,
                    'applied_by_user_id' => $userId,
                    'applied_at' => now(),
                ]);

                $newRemaining = $this->money->subtract($available, $take);
                $credit->update([
                    'remaining_amount' => $newRemaining,
                    'status' => $this->money->compare($newRemaining, '0.00') === 1
                        ? 'Available'
                        : 'Consumed',
                ]);

                $applied = $this->money->add($applied, $take);
                $remaining = $this->money->subtract($remaining, $take);
            }

            return $applied;
        }, attempts: 3);
    }

    private function create(
        ?int $bookingId,
        ?int $reservationId,
        string $sourceType,
        ?int $sourceId,
        string $amount,
        string $reason,
        ?int $userId,
    ): TransactionCredit {
        $amount = $this->money->normalize($amount);

        if ($this->money->compare($amount, '0.00') !== 1) {
            throw new InvalidArgumentException('Transaction credit must be greater than zero.');
        }

        if (($bookingId === null) === ($reservationId === null)) {
            throw new InvalidArgumentException(
                'Transaction credit must belong to exactly one reservation or booking.',
            );
        }

        return TransactionCredit::query()->create([
            'booking_id' => $bookingId,
            'reservation_id' => $reservationId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'original_amount' => $amount,
            'remaining_amount' => $amount,
            'status' => 'Available',
            'reason' => trim($reason),
            'created_by_user_id' => $userId,
        ]);
    }
}

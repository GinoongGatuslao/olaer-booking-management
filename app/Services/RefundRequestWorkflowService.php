<?php

namespace App\Services;

use App\Models\RefundRequest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RefundRequestWorkflowService
{
    /** Attempts allowed per reservation (approved, rejected and pending all count). */
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly DecimalMoneyService $money,
    ) {}

    /** Total of the reservation's verified payments: the most that can ever be refunded. */
    public function amountPaid(Reservation $reservation): string
    {
        return $this->money->add(...$reservation->payments
            ->filter(fn ($payment): bool => strtolower(trim((string) $payment->payment_status)) === 'verified')
            ->pluck('amount_paid')
            ->map(fn (mixed $amount): string => (string) $amount)
            ->all());
    }

    /**
     * Whether a cashier may send a refund request right now, and why not.
     * There are no policy rules on purpose (the resort decides case by case);
     * the only limits are: a cancelled reservation with a verified payment,
     * one open request at a time, and 3 attempts.
     *
     * @return array{can_request: bool, reason: ?string, paid: string, used: int, max: int, next_attempt: int, latest: ?RefundRequest}
     */
    public function eligibility(Reservation $reservation): array
    {
        $requests = $reservation->refundRequests->sortBy('attempt_no')->values();
        $paid = $this->amountPaid($reservation);
        $used = $requests->count();

        $reason = null;

        if ((string) $reservation->status !== 'Cancelled') {
            $reason = 'Refunds can only be requested for cancelled reservations.';
        } elseif ($this->money->compare($paid, '0.00') <= 0) {
            $reason = 'There is no verified payment to refund.';
        } elseif ($requests->contains('status', RefundRequest::STATUS_PENDING)) {
            $reason = 'Waiting for the admin to respond to the current refund request.';
        } elseif ($requests->contains('status', RefundRequest::STATUS_APPROVED)) {
            $reason = 'A refund was already approved for this reservation.';
        } elseif ($used >= self::MAX_ATTEMPTS) {
            $reason = 'All '.self::MAX_ATTEMPTS.' refund request attempts were used.';
        }

        return [
            'can_request' => $reason === null,
            'reason' => $reason,
            'paid' => $paid,
            'used' => $used,
            'max' => self::MAX_ATTEMPTS,
            'next_attempt' => $used + 1,
            'latest' => $requests->last(),
        ];
    }

    public function submit(
        int $reservationId,
        string $amount,
        ?string $note,
        int $cashierUserId,
    ): RefundRequest {
        $this->guardRole($cashierUserId, 'Cashier', 'send refund requests');

        $note = trim((string) $note);

        if (mb_strlen($note) > 1000) {
            throw new InvalidArgumentException('The note must not exceed 1000 characters.');
        }

        return DB::transaction(function () use ($reservationId, $amount, $note, $cashierUserId): RefundRequest {
            $reservation = Reservation::query()
                ->lockForUpdate()
                ->findOrFail($reservationId);

            $reservation->load(['payments', 'refundRequests']);

            $eligibility = $this->eligibility($reservation);

            if (! $eligibility['can_request']) {
                throw new InvalidArgumentException((string) $eligibility['reason']);
            }

            $amount = $this->parseAmount($amount, 'The refund amount');

            if ($this->money->compare($amount, $eligibility['paid']) > 0) {
                throw new InvalidArgumentException(
                    'The refund amount cannot be more than the verified payments (₱'.number_format((float) $eligibility['paid'], 2).').',
                );
            }

            return RefundRequest::query()->create([
                'reservation_id' => $reservation->reservation_id,
                'attempt_no' => $eligibility['next_attempt'],
                'requested_by_user_id' => $cashierUserId,
                'requested_amount' => $amount,
                'request_note' => $note === '' ? null : $note,
                'status' => RefundRequest::STATUS_PENDING,
            ]);
        });
    }

    /**
     * @param  'approve'|'reject'  $decision
     * @param  ?string  $approvedAmount  the most the cashier may give back (approve only)
     */
    public function review(
        int $refundRequestId,
        string $decision,
        string $note,
        ?string $approvedAmount,
        int $adminUserId,
    ): RefundRequest {
        $this->guardRole($adminUserId, 'Admin', 'respond to refund requests');

        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Choose whether to approve or reject the request.');
        }

        $note = trim($note);

        if ($note === '') {
            throw new InvalidArgumentException('Add a note explaining your decision.');
        }

        if (mb_strlen($note) > 1000) {
            throw new InvalidArgumentException('The note must not exceed 1000 characters.');
        }

        return DB::transaction(function () use ($refundRequestId, $decision, $note, $approvedAmount, $adminUserId): RefundRequest {
            $request = RefundRequest::query()
                ->lockForUpdate()
                ->findOrFail($refundRequestId);

            if ((string) $request->status !== RefundRequest::STATUS_PENDING) {
                throw new InvalidArgumentException('This refund request was already answered.');
            }

            $approved = null;

            if ($decision === 'approve') {
                $reservation = Reservation::query()
                    ->with('payments')
                    ->findOrFail($request->reservation_id);

                $paid = $this->amountPaid($reservation);
                $approved = $this->parseAmount((string) $approvedAmount, 'The approved refund amount');

                if ($this->money->compare($approved, $paid) > 0) {
                    throw new InvalidArgumentException(
                        'The approved refund cannot be more than the verified payments (₱'.number_format((float) $paid, 2).').',
                    );
                }
            }

            $request->update([
                'status' => $decision === 'approve'
                    ? RefundRequest::STATUS_APPROVED
                    : RefundRequest::STATUS_REJECTED,
                'approved_amount' => $approved,
                'admin_note' => $note,
                'reviewed_by_user_id' => $adminUserId,
                'reviewed_at' => Carbon::now(),
            ]);

            return $request->fresh(['reservation', 'requestedBy', 'reviewedBy']);
        });
    }

    private function parseAmount(string $amount, string $label): string
    {
        $amount = trim(str_replace(',', '', $amount));

        if ($amount === '' || ! is_numeric($amount)) {
            throw new InvalidArgumentException("{$label} must be a valid amount.");
        }

        $amount = $this->money->normalize($amount);

        if ($this->money->compare($amount, '0.00') <= 0) {
            throw new InvalidArgumentException("{$label} must be greater than zero.");
        }

        return $amount;
    }

    private function guardRole(int $userId, string $role, string $action): void
    {
        $user = $userId > 0
            ? User::query()->find($userId)
            : null;

        if ($user === null || ! $user->role()->where('role_name', $role)->exists()) {
            throw new InvalidArgumentException("Only {$role} accounts can {$action}.");
        }
    }
}

<?php

namespace App\Services;

use App\Models\ModeOfPayment;
use App\Models\RefundRequest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
     * The admin creates a refund request for a cancelled reservation themselves.
     * There is nobody to ask for approval, so it is recorded as approved and
     * given on the spot: amount, mode (Cash, or GCash with reference and proof).
     * It counts as one of the reservation's attempts and reduces revenue today.
     */
    public function createByAdmin(
        int $reservationId,
        string $amount,
        ?string $note,
        int $modeOfPaymentId,
        ?string $referenceNumber,
        ?string $proofPath,
        int $adminUserId,
    ): RefundRequest {
        $this->guardRole($adminUserId, 'Admin', 'create refunds');

        $note = trim((string) $note);

        if (mb_strlen($note) > 1000) {
            throw new InvalidArgumentException('The note must not exceed 1000 characters.');
        }

        $payout = $this->preparePayout($modeOfPaymentId, $referenceNumber, $proofPath);

        return DB::transaction(function () use ($reservationId, $amount, $note, $payout, $adminUserId): RefundRequest {
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

            $this->assertReferenceUnused($payout, 0);

            $now = Carbon::now();

            return RefundRequest::query()->create([
                'reservation_id' => $reservation->reservation_id,
                'attempt_no' => $eligibility['next_attempt'],
                'requested_by_user_id' => $adminUserId,
                'requested_amount' => $amount,
                'request_note' => null,
                'status' => RefundRequest::STATUS_APPROVED,
                'reviewed_by_user_id' => $adminUserId,
                'reviewed_at' => $now,
                'approved_amount' => $amount,
                'admin_note' => $note === '' ? 'Refund created by the admin.' : $note,
                'refunded_amount' => $amount,
                'refund_mode_of_payment_id' => $payout['mode']->mode_of_payment_id,
                'refund_reference_number' => $payout['reference'],
                'refund_proof_path' => $payout['proof'],
                'refunded_at' => $now,
                'refunded_by_user_id' => $adminUserId,
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
        ?int $modeOfPaymentId = null,
        ?string $referenceNumber = null,
        ?string $proofPath = null,
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

        // Approving means the admin gives the refund now, so how it is paid out
        // (Cash or GCash with reference and proof) is part of the approval.
        $payout = $decision === 'approve'
            ? $this->preparePayout($modeOfPaymentId, $referenceNumber, $proofPath)
            : null;

        return DB::transaction(function () use ($refundRequestId, $decision, $note, $approvedAmount, $adminUserId, $payout): RefundRequest {
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

            $payoutColumns = [];

            if ($decision === 'approve' && $payout !== null) {
                $this->assertReferenceUnused($payout, $request->refund_request_id);

                $payoutColumns = [
                    'refunded_amount' => $approved,
                    'refund_mode_of_payment_id' => $payout['mode']->mode_of_payment_id,
                    'refund_reference_number' => $payout['reference'],
                    'refund_proof_path' => $payout['proof'],
                    'refunded_at' => Carbon::now(),
                    'refunded_by_user_id' => $adminUserId,
                ];
            }

            $request->update([
                'status' => $decision === 'approve'
                    ? RefundRequest::STATUS_APPROVED
                    : RefundRequest::STATUS_REJECTED,
                'approved_amount' => $approved,
                'admin_note' => $note,
                'reviewed_by_user_id' => $adminUserId,
                'reviewed_at' => Carbon::now(),
            ] + $payoutColumns);

            return $request->fresh(['reservation', 'requestedBy', 'reviewedBy', 'refundModeOfPayment']);
        });
    }

    /**
     * Records the actual payout of a refund the admin approved earlier (before
     * approving also meant paying out). New approvals record the payout in
     * review(). The refund reduces revenue from the day it is recorded.
     */
    public function release(
        int $refundRequestId,
        int $modeOfPaymentId,
        ?string $amount,
        ?string $referenceNumber,
        ?string $proofPath,
        int $adminUserId,
    ): RefundRequest {
        $this->guardRole($adminUserId, 'Admin', 'record refunds given to guests');

        $payout = $this->preparePayout($modeOfPaymentId, $referenceNumber, $proofPath);

        return DB::transaction(function () use ($refundRequestId, $payout, $amount, $adminUserId): RefundRequest {
            $request = RefundRequest::query()
                ->lockForUpdate()
                ->findOrFail($refundRequestId);

            if ((string) $request->status !== RefundRequest::STATUS_APPROVED) {
                throw new InvalidArgumentException('Only an approved refund can be recorded as given to the guest.');
            }

            if ($request->refunded_at !== null) {
                throw new InvalidArgumentException('This refund was already given to the guest.');
            }

            $approved = $this->money->normalize((string) $request->approved_amount);

            // The admin states what was actually handed over; it is never assumed,
            // because this figure is what reduces revenue.
            if ($amount === null || trim($amount) === '') {
                throw new InvalidArgumentException('Enter the amount actually given to the guest.');
            }

            $given = $this->parseAmount($amount, 'The amount given');

            if ($this->money->compare($given, $approved) > 0) {
                throw new InvalidArgumentException(
                    'The refund cannot be more than the amount approved (₱'.number_format((float) $approved, 2).').',
                );
            }

            $this->assertReferenceUnused($payout, $request->refund_request_id);

            $request->update([
                'refunded_amount' => $given,
                'refund_mode_of_payment_id' => $payout['mode']->mode_of_payment_id,
                'refund_reference_number' => $payout['reference'],
                'refund_proof_path' => $payout['proof'],
                'refunded_at' => Carbon::now(),
                'refunded_by_user_id' => $adminUserId,
            ]);

            return $request->fresh(['reservation', 'refundModeOfPayment', 'refundedBy']);
        });
    }

    /**
     * Checks how a refund is paid out. GCash needs the reference number and a
     * proof image; cash needs neither (they are dropped if sent).
     *
     * @return array{mode: ModeOfPayment, is_gcash: bool, reference: ?string, proof: ?string}
     */
    private function preparePayout(?int $modeOfPaymentId, ?string $referenceNumber, ?string $proofPath): array
    {
        // The refund columns come from the 2026_11_15_000000 migration. Without them the
        // save fails with a database error nobody can act on, so say what is wrong.
        if (! Schema::hasColumns('tbl_refund_request', [
            'refunded_amount',
            'refund_mode_of_payment_id',
            'refund_reference_number',
            'refund_proof_path',
            'refunded_at',
            'refunded_by_user_id',
        ])) {
            throw new InvalidArgumentException(
                'Refund recording is not set up yet: the database is missing the refund columns. Ask the developer to run "php artisan migrate".',
            );
        }

        $mode = $modeOfPaymentId === null ? null : ModeOfPayment::query()->find($modeOfPaymentId);

        if ($mode === null) {
            throw new InvalidArgumentException('Choose how the refund is given to the guest.');
        }

        $isGcash = strtolower(trim((string) $mode->mode_of_payment)) === 'gcash';
        $referenceNumber = trim((string) $referenceNumber);

        if (! $isGcash) {
            return ['mode' => $mode, 'is_gcash' => false, 'reference' => null, 'proof' => null];
        }

        if ($referenceNumber === '') {
            throw new InvalidArgumentException('Enter the GCash reference number of the refund.');
        }

        if (mb_strlen($referenceNumber) > 50) {
            throw new InvalidArgumentException('The GCash reference number must not exceed 50 characters.');
        }

        if (blank($proofPath)) {
            throw new InvalidArgumentException('Upload the GCash proof image of the refund.');
        }

        return ['mode' => $mode, 'is_gcash' => true, 'reference' => $referenceNumber, 'proof' => $proofPath];
    }

    /** @param array{mode: ModeOfPayment, is_gcash: bool, reference: ?string, proof: ?string} $payout */
    private function assertReferenceUnused(array $payout, int $exceptRefundRequestId): void
    {
        if ($payout['is_gcash'] && RefundRequest::query()
            ->where('refund_reference_number', $payout['reference'])
            ->where('refund_request_id', '!=', $exceptRefundRequestId)
            ->exists()) {
            throw new InvalidArgumentException('This GCash reference number was already used for another refund.');
        }
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

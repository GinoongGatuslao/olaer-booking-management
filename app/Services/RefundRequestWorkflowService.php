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

    /**
     * The cashier asks the admin for a refund. The cashier does not choose an amount:
     * the request is for everything the guest paid (verified), and the admin decides
     * how much to approve. A note explaining the case is required.
     */
    public function submit(
        int $reservationId,
        ?string $note,
        int $cashierUserId,
    ): RefundRequest {
        $this->guardRole($cashierUserId, 'Cashier', 'send refund requests');

        $note = trim((string) $note);

        if ($note === '') {
            throw new InvalidArgumentException('Add a note to the admin explaining why this refund is needed.');
        }

        if (mb_strlen($note) > 1000) {
            throw new InvalidArgumentException('The note must not exceed 1000 characters.');
        }

        return DB::transaction(function () use ($reservationId, $note, $cashierUserId): RefundRequest {
            $reservation = Reservation::query()
                ->lockForUpdate()
                ->findOrFail($reservationId);

            $reservation->load(['payments', 'refundRequests']);

            $eligibility = $this->eligibility($reservation);

            if (! $eligibility['can_request']) {
                throw new InvalidArgumentException((string) $eligibility['reason']);
            }

            $amount = $this->money->normalize($eligibility['paid']);

            return RefundRequest::query()->create([
                'reservation_id' => $reservation->reservation_id,
                'attempt_no' => $eligibility['next_attempt'],
                'requested_by_user_id' => $cashierUserId,
                'requested_amount' => $amount,
                'request_note' => $note,
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
        ?string $note,
        ?string $approvedAmount,
        int $adminUserId,
    ): RefundRequest {
        $this->guardRole($adminUserId, 'Admin', 'respond to refund requests');

        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Choose whether to approve or reject the request.');
        }

        // The admin's note is optional.
        $note = trim((string) $note);

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
                'admin_note' => $note === '' ? null : $note,
                'reviewed_by_user_id' => $adminUserId,
                'reviewed_at' => Carbon::now(),
            ]);

            return $request->fresh(['reservation', 'requestedBy', 'reviewedBy']);
        });
    }

    /**
     * The cashier gives an approved refund back to the guest (cash and any other
     * non-GCash mode) and records how. GCash refunds go through sendGcashToAdmin()
     * and completeGcashRefund() instead: the admin sends the money.
     * The refund reduces revenue from the day it is given.
     */
    public function release(
        int $refundRequestId,
        int $modeOfPaymentId,
        ?string $amount,
        ?string $referenceNumber,
        ?string $proofPath,
        int $cashierUserId,
    ): RefundRequest {
        $this->guardRole($cashierUserId, 'Cashier', 'give refunds to guests');

        // The release columns come from the 2026_11_15_000000 migration. Without them the
        // save fails with a database error the cashier cannot act on, so say what is wrong.
        if (! Schema::hasColumns('tbl_refund_request', [
            'refunded_amount',
            'refund_mode_of_payment_id',
            'refund_reference_number',
            'refund_proof_path',
            'refunded_at',
            'refunded_by_user_id',
        ])) {
            throw new InvalidArgumentException(
                'Refund recording is not set up yet: the database is missing the refund release columns. Ask the developer to run "php artisan migrate".',
            );
        }

        $mode = ModeOfPayment::query()->find($modeOfPaymentId);

        if ($mode === null) {
            throw new InvalidArgumentException('Choose how the refund is given to the guest.');
        }

        if (strtolower(trim((string) $mode->mode_of_payment)) === 'gcash') {
            throw new InvalidArgumentException(
                'GCash refunds are sent to the admin, who sends the money and records the reference number and proof.',
            );
        }

        return DB::transaction(function () use ($refundRequestId, $mode, $amount, $cashierUserId): RefundRequest {
            $request = RefundRequest::query()
                ->lockForUpdate()
                ->findOrFail($refundRequestId);

            if ((string) $request->status !== RefundRequest::STATUS_APPROVED) {
                throw new InvalidArgumentException('Only an approved refund can be given to the guest.');
            }

            if ($request->refunded_at !== null) {
                throw new InvalidArgumentException('This refund was already given to the guest.');
            }

            if ($request->gcash_handoff_at !== null) {
                throw new InvalidArgumentException('This refund was already sent to the admin as a GCash refund.');
            }

            $approved = $this->money->normalize((string) $request->approved_amount);
            // The cashier must state what was actually handed over; it is never assumed to be
            // the approved amount, because this figure is what reduces revenue.
            if ($amount === null || trim($amount) === '') {
                throw new InvalidArgumentException('Enter the amount actually given to the guest.');
            }

            $given = $this->parseAmount($amount, 'The amount given');

            if ($this->money->compare($given, $approved) > 0) {
                throw new InvalidArgumentException(
                    'The refund cannot be more than the amount the admin approved (₱'.number_format((float) $approved, 2).').',
                );
            }

            $request->update([
                'refunded_amount' => $given,
                'refund_mode_of_payment_id' => $mode->mode_of_payment_id,
                'refund_reference_number' => null,
                'refund_proof_path' => null,
                'refunded_at' => Carbon::now(),
                'refunded_by_user_id' => $cashierUserId,
            ]);

            return $request->fresh(['reservation', 'refundModeOfPayment', 'refundedBy']);
        });
    }

    /**
     * GCash refund, step 1: the cashier sends an approved refund back to the admin with
     * a note, the guest's GCash contact number and the amount to send. Nothing is refunded yet, so revenue is
     * unchanged until the admin completes it.
     */
    public function sendGcashToAdmin(
        int $refundRequestId,
        ?string $amount,
        ?string $note,
        ?string $contactNumber,
        int $cashierUserId,
    ): RefundRequest {
        $this->guardRole($cashierUserId, 'Cashier', 'send GCash refunds to the admin');
        $this->assertColumns([
            'gcash_handoff_amount',
            'gcash_handoff_note',
            'gcash_handoff_contact_number',
            'gcash_handoff_at',
            'gcash_handoff_by_user_id',
        ]);

        $note = trim((string) $note);

        if ($note === '') {
            throw new InvalidArgumentException('Add a note to the admin about this GCash refund.');
        }

        if (mb_strlen($note) > 1000) {
            throw new InvalidArgumentException('The note must not exceed 1000 characters.');
        }

        $contactNumber = trim((string) $contactNumber);

        if ($contactNumber === '') {
            throw new InvalidArgumentException('Enter the guest\'s GCash contact number.');
        }

        if (! preg_match('/^\d{11}$/', $contactNumber)) {
            throw new InvalidArgumentException('The GCash contact number must be 11 digits, numbers only.');
        }

        if ($amount === null || trim($amount) === '') {
            throw new InvalidArgumentException('Enter the amount to refund through GCash.');
        }

        return DB::transaction(function () use ($refundRequestId, $amount, $note, $contactNumber, $cashierUserId): RefundRequest {
            $request = RefundRequest::query()
                ->lockForUpdate()
                ->findOrFail($refundRequestId);

            if ((string) $request->status !== RefundRequest::STATUS_APPROVED) {
                throw new InvalidArgumentException('Only an approved refund can be sent to the admin for GCash.');
            }

            if ($request->refunded_at !== null) {
                throw new InvalidArgumentException('This refund was already given to the guest.');
            }

            if ($request->gcash_handoff_at !== null) {
                throw new InvalidArgumentException('This refund was already sent to the admin.');
            }

            $approved = $this->money->normalize((string) $request->approved_amount);
            $toSend = $this->parseAmount($amount, 'The GCash refund amount');

            if ($this->money->compare($toSend, $approved) > 0) {
                throw new InvalidArgumentException(
                    'The refund cannot be more than the amount the admin approved (₱'.number_format((float) $approved, 2).').',
                );
            }

            $request->update([
                'gcash_handoff_amount' => $toSend,
                'gcash_handoff_note' => $note,
                'gcash_handoff_contact_number' => $contactNumber,
                'gcash_handoff_at' => Carbon::now(),
                'gcash_handoff_by_user_id' => $cashierUserId,
            ]);

            return $request->fresh(['reservation', 'requestedBy', 'gcashHandoffBy']);
        });
    }

    /**
     * GCash refund, step 2: the admin has sent the money and records the GCash reference
     * number and proof image. This completes the refund; it reduces revenue from today.
     */
    public function completeGcashRefund(
        int $refundRequestId,
        ?string $amount,
        ?string $referenceNumber,
        ?string $proofPath,
        int $adminUserId,
    ): RefundRequest {
        $this->guardRole($adminUserId, 'Admin', 'complete GCash refunds');
        $this->assertColumns([
            'refunded_amount',
            'refund_mode_of_payment_id',
            'refund_reference_number',
            'refund_proof_path',
            'refunded_at',
            'refunded_by_user_id',
            'gcash_handoff_at',
        ]);

        $mode = ModeOfPayment::query()
            ->whereRaw('LOWER(TRIM(mode_of_payment)) = ?', ['gcash'])
            ->first();

        if ($mode === null) {
            throw new InvalidArgumentException('The GCash mode of payment does not exist. Add it before completing GCash refunds.');
        }

        $referenceNumber = trim((string) $referenceNumber);

        if ($referenceNumber === '') {
            throw new InvalidArgumentException('Enter the GCash reference number of the refund.');
        }

        if (mb_strlen($referenceNumber) > 50) {
            throw new InvalidArgumentException('The GCash reference number must not exceed 50 characters.');
        }

        if (blank($proofPath)) {
            throw new InvalidArgumentException('Upload the GCash proof image of the refund.');
        }

        if ($amount === null || trim($amount) === '') {
            throw new InvalidArgumentException('Enter the amount you sent through GCash.');
        }

        return DB::transaction(function () use ($refundRequestId, $mode, $referenceNumber, $proofPath, $amount, $adminUserId): RefundRequest {
            $request = RefundRequest::query()
                ->lockForUpdate()
                ->findOrFail($refundRequestId);

            if ((string) $request->status !== RefundRequest::STATUS_APPROVED) {
                throw new InvalidArgumentException('Only an approved refund can be completed.');
            }

            if ($request->refunded_at !== null) {
                throw new InvalidArgumentException('This refund was already completed.');
            }

            if ($request->gcash_handoff_at === null) {
                throw new InvalidArgumentException('The cashier has not sent this refund to you for GCash yet.');
            }

            $approved = $this->money->normalize((string) $request->approved_amount);
            $sent = $this->parseAmount($amount, 'The amount sent');

            if ($this->money->compare($sent, $approved) > 0) {
                throw new InvalidArgumentException(
                    'The refund cannot be more than the amount approved (₱'.number_format((float) $approved, 2).').',
                );
            }

            if (RefundRequest::query()
                ->where('refund_reference_number', $referenceNumber)
                ->where('refund_request_id', '!=', $request->refund_request_id)
                ->exists()) {
                throw new InvalidArgumentException('This GCash reference number was already used for another refund.');
            }

            $request->update([
                'refunded_amount' => $sent,
                'refund_mode_of_payment_id' => $mode->mode_of_payment_id,
                'refund_reference_number' => $referenceNumber,
                'refund_proof_path' => $proofPath,
                'refunded_at' => Carbon::now(),
                'refunded_by_user_id' => $adminUserId,
            ]);

            return $request->fresh(['reservation', 'refundModeOfPayment', 'refundedBy']);
        });
    }

    /** @param  list<string>  $columns */
    private function assertColumns(array $columns): void
    {
        if (! Schema::hasColumns('tbl_refund_request', $columns)) {
            throw new InvalidArgumentException(
                'Refund recording is not set up yet: the database is missing refund columns. Ask the developer to run "php artisan migrate".',
            );
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

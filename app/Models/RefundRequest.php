<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundRequest extends Model
{
    public const STATUS_PENDING = 'Pending';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    protected $table = 'tbl_refund_request';

    protected $primaryKey = 'refund_request_id';

    protected $fillable = [
        'reservation_id',
        'booking_id',
        'attempt_no',
        'requested_by_user_id',
        'requested_amount',
        'request_note',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'approved_amount',
        'admin_note',
        'refunded_amount',
        'refund_mode_of_payment_id',
        'refund_reference_number',
        'refund_proof_path',
        'refunded_at',
        'refunded_by_user_id',
        'gcash_handoff_amount',
        'gcash_handoff_note',
        'gcash_handoff_contact_number',
        'gcash_handoff_at',
        'gcash_handoff_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'refunded_amount' => 'decimal:2',
            'refunded_at' => 'datetime',
            'gcash_handoff_amount' => 'decimal:2',
            'gcash_handoff_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id', 'reservation_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function isForBooking(): bool
    {
        return $this->booking_id !== null;
    }

    /** The reservation or booking this refund belongs to. */
    public function subject(): Reservation|Booking|null
    {
        return $this->isForBooking() ? $this->booking : $this->reservation;
    }

    public function subjectLabel(): string
    {
        return $this->isForBooking() ? 'Booking' : 'Reservation';
    }

    public function referenceNumber(): ?string
    {
        return $this->isForBooking()
            ? $this->booking?->b_ref_no
            : $this->reservation?->r_ref_no;
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id', 'user_id');
    }

    /** Approved by the admin and already given back to the guest by the cashier. */
    public function isGivenToGuest(): bool
    {
        return $this->refunded_at !== null;
    }

    /**
     * Approved by the admin, waiting for the cashier to give the money back.
     * GCash refunds the cashier already handed to the admin are not the cashier's to give.
     */
    public function isWaitingForRelease(): bool
    {
        return (string) $this->status === self::STATUS_APPROVED
            && $this->refunded_at === null
            && $this->gcash_handoff_at === null;
    }

    /** A GCash refund the cashier sent back to the admin, who still has to send it and record the proof. */
    public function isAwaitingAdminGcash(): bool
    {
        return (string) $this->status === self::STATUS_APPROVED
            && $this->refunded_at === null
            && $this->gcash_handoff_at !== null;
    }

    public function gcashHandoffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gcash_handoff_by_user_id', 'user_id');
    }

    public function refundModeOfPayment(): BelongsTo
    {
        return $this->belongsTo(ModeOfPayment::class, 'refund_mode_of_payment_id', 'mode_of_payment_id');
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by_user_id', 'user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id', 'user_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'tbl_payment';

    protected $primaryKey = 'payment_id';

    protected $fillable = [
        'p_ref_no',
        'booking_id',
        'reservation_id',
        'entrance_slip_id',
        'mode_of_payment_id',
        'reference_number',
        'proof_of_payment_path',
        'amount_paid',
        'date_paid',
        'user_id',
        'payment_status',
        'verified_by_user_id',
        'verified_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'date_paid' => 'date',
            'verified_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id', 'reservation_id');
    }

    public function entranceSlip(): BelongsTo
    {
        return $this->belongsTo(EntranceSlip::class, 'entrance_slip_id', 'entrance_slip_id');
    }

    public function modeOfPayment(): BelongsTo
    {
        return $this->belongsTo(ModeOfPayment::class, 'mode_of_payment_id', 'mode_of_payment_id');
    }

    /** Staff/cashier who recorded the payment. Nullable for guest-uploaded GCash payments. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id', 'user_id');
    }
}

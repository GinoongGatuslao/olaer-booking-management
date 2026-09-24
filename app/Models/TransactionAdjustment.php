<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionAdjustment extends Model
{
    protected $table = 'tbl_transaction_adjustments';

    protected $primaryKey = 'transaction_adjustment_id';

    protected $fillable = [
        'reservation_id',
        'booking_id',
        'entrance_slip_id',
        'direction',
        'amount',
        'reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id', 'reservation_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function entranceSlip(): BelongsTo
    {
        return $this->belongsTo(EntranceSlip::class, 'entrance_slip_id', 'entrance_slip_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }
}

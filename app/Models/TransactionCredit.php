<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionCredit extends Model
{
    protected $table = 'tbl_transaction_credits';

    protected $primaryKey = 'transaction_credit_id';

    protected $fillable = [
        'reservation_id',
        'booking_id',
        'source_type',
        'source_id',
        'original_amount',
        'remaining_amount',
        'status',
        'reason',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'original_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TransactionCreditAllocation::class, 'transaction_credit_id', 'transaction_credit_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionCreditAllocation extends Model
{
    protected $table = 'tbl_transaction_credit_allocations';

    protected $primaryKey = 'transaction_credit_allocation_id';

    protected $fillable = [
        'transaction_credit_id',
        'target_type',
        'target_id',
        'amount',
        'applied_by_user_id',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'amount' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(TransactionCredit::class, 'transaction_credit_id', 'transaction_credit_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id', 'user_id');
    }
}

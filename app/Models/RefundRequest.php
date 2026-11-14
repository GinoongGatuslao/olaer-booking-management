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
        'attempt_no',
        'requested_by_user_id',
        'requested_amount',
        'request_note',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'approved_amount',
        'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id', 'reservation_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id', 'user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id', 'user_id');
    }
}

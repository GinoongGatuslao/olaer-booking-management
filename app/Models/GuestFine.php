<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestFine extends Model
{
    use HasFactory;

    protected $table = 'tbl_guest_fine';

    protected $primaryKey = 'guest_fine_id';

    protected $fillable = [
        'booking_id',
        'fine_id',
        'quantity',
        'facility_id',
        'total_charge',
        'date_checked',
        'reported_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'total_charge' => 'decimal:2',
            'date_checked' => 'date',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class, 'fine_id', 'fine_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id', 'user_id');
    }
}

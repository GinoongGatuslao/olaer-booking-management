<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingDetail extends Model
{
    use HasFactory;

    protected $table = 'tbl_booking_details';

    protected $primaryKey = 'booking_details_id';

    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'facility_id',
        'rate_type',
        'check_in_date',
        'check_out_date',
        'check_in_time',
        'status',
        'discount_id',
        'user_id',
        'base_price',
        'discount_amount',
        'extra_guest_fee',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'base_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'extra_guest_fee' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discount_id', 'discount_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}

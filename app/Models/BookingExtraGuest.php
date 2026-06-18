<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingExtraGuest extends Model
{
    use HasFactory;

    protected $table = 'tbl_booking_extra_guests';

    protected $primaryKey = 'booking_extra_guest_id';

    public $timestamps = false;

    protected $fillable = [
        'booking_id',
        'first_name',
        'middle_name',
        'last_name',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
    }
}

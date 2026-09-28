<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingRoomOccupant extends Model
{
    protected $table = 'tbl_booking_room_occupants';
    protected $primaryKey = 'booking_room_occupant_id';

    protected $fillable = [
        'booking_details_id',
        'position',
        'first_name',
        'middle_name',
        'last_name',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function bookingDetail(): BelongsTo
    {
        return $this->belongsTo(
            BookingDetail::class,
            'booking_details_id',
            'booking_details_id',
        );
    }
}

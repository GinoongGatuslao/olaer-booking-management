<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationRoomOccupant extends Model
{
    protected $table = 'tbl_reservation_room_occupants';
    protected $primaryKey = 'reservation_room_occupant_id';

    protected $fillable = [
        'reservation_details_id',
        'position',
        'first_name',
        'middle_name',
        'last_name',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function reservationDetail(): BelongsTo
    {
        return $this->belongsTo(
            ReservationDetail::class,
            'reservation_details_id',
            'reservation_details_id',
        );
    }
}

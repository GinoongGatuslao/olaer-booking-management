<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationExtraGuest extends Model
{
    use HasFactory;

    protected $table = 'tbl_reservation_extra_guests';

    protected $primaryKey = 'reservation_extra_guest_id';

    public $timestamps = false;

    protected $fillable = [
        'reservation_id',
        'reservation_details_id',
        'first_name',
        'middle_name',
        'last_name',
    ];

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id', 'reservation_id');
    }

    /** @return BelongsTo<ReservationDetail, $this> */
    public function reservationDetail(): BelongsTo
    {
        return $this->belongsTo(ReservationDetail::class, 'reservation_details_id', 'reservation_details_id');
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

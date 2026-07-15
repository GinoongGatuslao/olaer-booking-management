<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    use HasFactory;

    protected $table = 'tbl_reservation';

    protected $primaryKey = 'reservation_id';

    protected $fillable = [
        'r_ref_no',
        'guest_id',
        'reservation_date',
        'total_price',
        'amount_due',
        'no_of_extra_guests',
        'total_guest_count',
        'user_id',
        'status',
        'cancellation_reason',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'date',
            'total_price' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'no_of_extra_guests' => 'integer',
            'total_guest_count' => 'integer',
            'cancelled_at' => 'date',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id', 'guest_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ReservationDetail::class, 'reservation_id', 'reservation_id');
    }

    public function extraGuests(): HasMany
    {
        return $this->hasMany(ReservationExtraGuest::class, 'reservation_id', 'reservation_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'reservation_id', 'reservation_id');
    }

    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class, 'reservation_id', 'reservation_id');
    }
}

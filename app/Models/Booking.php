<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use HasFactory;

    protected $table = 'tbl_booking';

    protected $primaryKey = 'booking_id';

    protected $fillable = [
        'b_ref_no',
        'guest_id',
        'booking_date',
        'no_of_extra_guests',
        'total_guest_count',
        'total_price',
        'amount_due',
        'user_id',
        'reservation_id',
        'entrance_slip_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'no_of_extra_guests' => 'integer',
            'total_guest_count' => 'integer',
            'total_price' => 'decimal:2',
            'amount_due' => 'decimal:2',
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

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id', 'reservation_id');
    }

    public function entranceSlip(): BelongsTo
    {
        return $this->belongsTo(EntranceSlip::class, 'entrance_slip_id', 'entrance_slip_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(BookingDetail::class, 'booking_id', 'booking_id');
    }

    public function extraGuests(): HasMany
    {
        return $this->hasMany(BookingExtraGuest::class, 'booking_id', 'booking_id');
    }

    public function amenityRequests(): HasMany
    {
        return $this->hasMany(AmenityRequest::class, 'booking_id', 'booking_id');
    }

    public function guestFines(): HasMany
    {
        return $this->hasMany(GuestFine::class, 'booking_id', 'booking_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'booking_id', 'booking_id');
    }
}

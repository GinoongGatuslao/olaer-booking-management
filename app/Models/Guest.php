<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{
    use HasFactory;

    protected $table = 'tbl_guest';

    protected $primaryKey = 'guest_id';

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'contact_no',
        'address_id',
        'email',
    ];

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'address_id', 'address_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'guest_id', 'guest_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'guest_id', 'guest_id');
    }

    public function entranceSlips(): HasMany
    {
        return $this->hasMany(EntranceSlip::class, 'guest_id', 'guest_id');
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

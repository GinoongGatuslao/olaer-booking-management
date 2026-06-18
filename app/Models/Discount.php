<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Discount extends Model
{
    use HasFactory;

    protected $table = 'tbl_discount';

    protected $primaryKey = 'discount_id';

    protected $fillable = [
        'discount_name',
        'discount_amount',
        'app_to_adult',
        'app_to_children',
        'app_to_SC_PWD',
        'app_to_cottage',
        'app_to_room',
        'app_to_function_hall',
        'discount_start',
        'discount_end',
        'status',
    ];

    protected function casts(): array
    {
        return [
            // Stored as decimal fraction to match the capstone examples:
            // 0.10 = 10%, 0.50 = 50%.
            'discount_amount' => 'decimal:2',
            'app_to_adult' => 'boolean',
            'app_to_children' => 'boolean',
            'app_to_SC_PWD' => 'boolean',
            'app_to_cottage' => 'boolean',
            'app_to_room' => 'boolean',
            'app_to_function_hall' => 'boolean',
            'discount_start' => 'datetime',
            'discount_end' => 'datetime',
        ];
    }

    public function reservationDetails(): HasMany
    {
        return $this->hasMany(ReservationDetail::class, 'discount_id', 'discount_id');
    }

    public function entranceSlipDetails(): HasMany
    {
        return $this->hasMany(EntranceSlipDetail::class, 'discount_id', 'discount_id');
    }

    public function bookingDetails(): HasMany
    {
        return $this->hasMany(BookingDetail::class, 'discount_id', 'discount_id');
    }
}

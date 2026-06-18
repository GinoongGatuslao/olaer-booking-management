<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EntranceSlip extends Model
{
    use HasFactory;

    protected $table = 'tbl_entrance_slip';

    protected $primaryKey = 'entrance_slip_id';

    protected $fillable = [
        'no_of_adult',
        'no_of_children',
        'no_of_PWD_SC',
        'no_of_Male',
        'no_of_Female',
        'no_of_Tourist',
        'created_by_user_id',
        'guest_id',
        'date_created',
        'time_created',
        'total_price',
        'amount_due',
        'handled_by_user_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'no_of_adult' => 'integer',
            'no_of_children' => 'integer',
            'no_of_PWD_SC' => 'integer',
            'no_of_Male' => 'integer',
            'no_of_Female' => 'integer',
            'no_of_Tourist' => 'integer',
            'date_created' => 'date',
            'total_price' => 'decimal:2',
            'amount_due' => 'decimal:2',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id', 'user_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id', 'guest_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(EntranceSlipDetail::class, 'entrance_slip_id', 'entrance_slip_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'entrance_slip_id', 'entrance_slip_id');
    }

    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class, 'entrance_slip_id', 'entrance_slip_id');
    }
}

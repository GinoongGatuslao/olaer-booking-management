<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmenityRequest extends Model
{
    use HasFactory;

    protected $table = 'tbl_amenity_request';

    protected $primaryKey = 'amenity_request_id';

    protected $fillable = [
        'booking_id',
        'amenity_request_status',
        'total_price',
        'date_created',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'total_price' => 'decimal:2',
            'date_created' => 'date',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(AmenityRequestDetail::class, 'amenity_request_id', 'amenity_request_id');
    }
}

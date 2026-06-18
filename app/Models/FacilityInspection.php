<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacilityInspection extends Model
{
    use HasFactory;

    protected $table = 'tbl_facility_inspection';

    protected $primaryKey = 'facility_inspection_id';

    protected $fillable = [
        'booking_details_id',
        'booking_id',
        'facility_id',
        'inspected_by_user_id',
        'inspection_status',
        'remarks',
        'inspected_at',
    ];

    protected function casts(): array
    {
        return [
            'inspected_at' => 'datetime',
        ];
    }

    public function bookingDetail(): BelongsTo
    {
        return $this->belongsTo(BookingDetail::class, 'booking_details_id', 'booking_details_id');
    }

    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_user_id', 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FacilityInspectionItem::class, 'facility_inspection_id', 'facility_inspection_id');
    }
}

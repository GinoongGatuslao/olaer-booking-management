<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FacilityInspectionRequest extends Model
{
    use HasFactory;

    protected $table = 'tbl_facility_inspection_request';

    protected $primaryKey = 'facility_inspection_request_id';

    protected $fillable = [
        'booking_id',
        'booking_details_id',
        'facility_id',
        'requested_by_user_id',
        'assigned_to_user_id',
        'status',
        'request_notes',
        'requested_at',
        'accepted_at',
        'completed_at',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'accepted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }

    public function bookingDetail(): BelongsTo
    {
        return $this->belongsTo(BookingDetail::class, 'booking_details_id', 'booking_details_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id', 'user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id', 'user_id');
    }

    public function inspection(): HasOne
    {
        return $this->hasOne(FacilityInspection::class, 'booking_details_id', 'booking_details_id');
    }
}

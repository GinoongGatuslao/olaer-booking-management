<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Facility extends Model
{
    use HasFactory;

    protected $table = 'tbl_facility';

    protected $primaryKey = 'facility_id';

    protected $fillable = [
        'facility_name',
        'facility_type_id',
        'facility_size',
        'facility_status',
        'capacity',
    ];

    public function facilityType(): BelongsTo
    {
        return $this->belongsTo(FacilityType::class, 'facility_type_id', 'facility_type_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(FacilityPrice::class, 'facility_id', 'facility_id');
    }

    public function facilityAmenities(): HasMany
    {
        return $this->hasMany(FacilityAmenity::class, 'facility_id', 'facility_id');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'tbl_facility_amenities', 'facility_id', 'amenity_id')
            ->withPivot(['facility_amenity_id', 'amenity_quantity']);
    }

    public function reservationDetails(): HasMany
    {
        return $this->hasMany(ReservationDetail::class, 'facility_id', 'facility_id');
    }

    public function bookingDetails(): HasMany
    {
        return $this->hasMany(BookingDetail::class, 'facility_id', 'facility_id');
    }

    public function amenityRequestDetails(): HasMany
    {
        return $this->hasMany(AmenityRequestDetail::class, 'facility_id', 'facility_id');
    }

    public function guestFines(): HasMany
    {
        return $this->hasMany(GuestFine::class, 'facility_id', 'facility_id');
    }
}

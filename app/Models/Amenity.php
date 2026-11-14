<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Amenity extends Model
{
    use HasFactory;

    protected $table = 'tbl_amenity';

    protected $primaryKey = 'amenity_id';

    protected $fillable = [
        'amenity_name_id',
        'amenity_description',
        'amenity_type',
        'amenity_price',
        'request_limit',
        'request_limit_cottage',
        'request_limit_room',
        'request_limit_function_hall',
    ];

    protected function casts(): array
    {
        return [
            'amenity_price' => 'decimal:2',
            'request_limit' => 'integer',
            'request_limit_cottage' => 'integer',
            'request_limit_room' => 'integer',
            'request_limit_function_hall' => 'integer',
        ];
    }

    /**
     * The most units of this amenity that can be requested for one facility of
     * the given type (Cottage, Room or Function Hall).
     */
    public function requestLimitFor(?string $facilityType): int
    {
        $column = match (strtolower(trim((string) $facilityType))) {
            'cottage' => 'request_limit_cottage',
            'room' => 'request_limit_room',
            'function hall' => 'request_limit_function_hall',
            default => 'request_limit',
        };

        return max((int) ($this->{$column} ?? $this->request_limit ?? 10), 1);
    }

    public function amenityName(): BelongsTo
    {
        return $this->belongsTo(AmenityName::class, 'amenity_name_id', 'amenity_name_id');
    }

    public function facilityAmenities(): HasMany
    {
        return $this->hasMany(FacilityAmenity::class, 'amenity_id', 'amenity_id');
    }

    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class, 'tbl_facility_amenities', 'amenity_id', 'facility_id')
            ->withPivot(['facility_amenity_id', 'amenity_quantity']);
    }

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class, 'amenity_id', 'amenity_id');
    }

    public function amenityRequestDetails(): HasMany
    {
        return $this->hasMany(AmenityRequestDetail::class, 'amenity_id', 'amenity_id');
    }
}

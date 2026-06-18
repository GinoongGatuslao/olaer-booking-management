<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityAmenity extends Model
{
    use HasFactory;

    protected $table = 'tbl_facility_amenities';

    protected $primaryKey = 'facility_amenity_id';

    public $timestamps = false;

    protected $fillable = [
        'facility_id',
        'amenity_id',
        'amenity_quantity',
    ];

    protected function casts(): array
    {
        return [
            'amenity_quantity' => 'integer',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }

    public function amenity(): BelongsTo
    {
        return $this->belongsTo(Amenity::class, 'amenity_id', 'amenity_id');
    }
}

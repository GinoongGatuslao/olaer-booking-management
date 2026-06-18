<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityInspectionItem extends Model
{
    use HasFactory;

    protected $table = 'tbl_facility_inspection_items';

    protected $primaryKey = 'facility_inspection_item_id';

    protected $fillable = [
        'facility_inspection_id',
        'item_source',
        'source_id',
        'amenity_id',
        'expected_quantity',
        'condition_status',
        'fine_id',
        'fine_quantity',
        'total_charge',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'integer',
            'fine_quantity' => 'integer',
            'total_charge' => 'decimal:2',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(FacilityInspection::class, 'facility_inspection_id', 'facility_inspection_id');
    }

    public function amenity(): BelongsTo
    {
        return $this->belongsTo(Amenity::class, 'amenity_id', 'amenity_id');
    }

    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class, 'fine_id', 'fine_id');
    }
}

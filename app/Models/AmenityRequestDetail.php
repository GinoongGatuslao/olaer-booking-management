<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmenityRequestDetail extends Model
{
    use HasFactory;

    protected $table = 'tbl_amenity_request_details';

    protected $primaryKey = 'amenity_request_detail_id';

    public $timestamps = false;

    protected $fillable = [
        'amenity_request_id',
        'facility_id',
        'amenity_id',
        'amenity_quantity',
        'unit_price',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'amenity_quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function amenityRequest(): BelongsTo
    {
        return $this->belongsTo(AmenityRequest::class, 'amenity_request_id', 'amenity_request_id');
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

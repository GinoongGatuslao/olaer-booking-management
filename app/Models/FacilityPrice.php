<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityPrice extends Model
{
    use HasFactory;

    protected $table = 'tbl_facility_price';

    protected $primaryKey = 'facility_price_id';

    public $timestamps = false;

    protected $fillable = [
        'facility_id',
        'rate_type',
        'facility_price',
    ];

    protected function casts(): array
    {
        return [
            'facility_price' => 'decimal:2',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }
}

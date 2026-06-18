<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacilityType extends Model
{
    use HasFactory;

    protected $table = 'tbl_facility_type';

    protected $primaryKey = 'facility_type_id';

    public $timestamps = false;

    protected $fillable = [
        'facility_type',
    ];

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'facility_type_id', 'facility_type_id');
    }
}

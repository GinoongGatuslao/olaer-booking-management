<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmenityName extends Model
{
    use HasFactory;

    protected $table = 'tbl_amenity_name';

    protected $primaryKey = 'amenity_name_id';

    public $timestamps = false;

    protected $fillable = [
        'amenity_name',
    ];

    public function amenities(): HasMany
    {
        return $this->hasMany(Amenity::class, 'amenity_name_id', 'amenity_name_id');
    }
}

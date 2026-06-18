<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DamageType extends Model
{
    use HasFactory;

    protected $table = 'tbl_damage_type';

    protected $primaryKey = 'damage_type_id';

    public $timestamps = false;

    protected $fillable = [
        'damage_type',
    ];

    public function fines(): HasMany
    {
        return $this->hasMany(Fine::class, 'damage_type_id', 'damage_type_id');
    }
}

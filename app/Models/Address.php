<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Address extends Model
{
    use HasFactory;

    protected $table = 'tbl_address';

    protected $primaryKey = 'address_id';

    public $timestamps = false;

    protected $fillable = [
        'purok',
        'province',
        'city',
        'barangay',
    ];

    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class, 'address_id', 'address_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'address_id', 'address_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fine extends Model
{
    use HasFactory;

    protected $table = 'tbl_fine';

    protected $primaryKey = 'fine_id';

    protected $fillable = [
        'fine_type',
        'amenity_id',
        'damage_type_id',
        'situational_fine',
        'situational_fine_description',
        'fine_charge',
    ];

    protected function casts(): array
    {
        return [
            'fine_charge' => 'decimal:2',
        ];
    }

    public function amenity(): BelongsTo
    {
        return $this->belongsTo(Amenity::class, 'amenity_id', 'amenity_id');
    }

    public function damageType(): BelongsTo
    {
        return $this->belongsTo(DamageType::class, 'damage_type_id', 'damage_type_id');
    }

    public function guestFines(): HasMany
    {
        return $this->hasMany(GuestFine::class, 'fine_id', 'fine_id');
    }
}

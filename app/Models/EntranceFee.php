<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntranceFee extends Model
{
    use HasFactory;

    protected $table = 'tbl_entrance_fee';

    protected $primaryKey = 'entrance_fee_id';

    protected $fillable = [
        'entrance_fee_name',
        'entrance_fee_price',
    ];

    protected function casts(): array
    {
        return [
            'entrance_fee_price' => 'decimal:2',
        ];
    }

    public function entranceSlipDetails(): HasMany
    {
        return $this->hasMany(EntranceSlipDetail::class, 'entrance_fee_id', 'entrance_fee_id');
    }
}

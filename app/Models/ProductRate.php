<?php

namespace App\Models;

use App\FacilityRateCode;
use Database\Factories\ProductRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property FacilityRateCode $rate_code
 * @property string $amount
 * @property bool $is_active
 */
class ProductRate extends Model
{
    /** @use HasFactory<ProductRateFactory> */
    use HasFactory;

    protected $table = 'tbl_facility_product_rate';

    protected $primaryKey = 'facility_product_rate_id';

    protected $fillable = [
        'facility_product_id',
        'rate_code',
        'display_name',
        'amount',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::updating(function (ProductRate $productRate): void {
            if ($productRate->isDirty('rate_code')) {
                throw new LogicException('Facility product rate codes cannot be changed.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'rate_code' => FacilityRateCode::class,
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<FacilityProduct, $this> */
    public function facilityProduct(): BelongsTo
    {
        return $this->belongsTo(FacilityProduct::class, 'facility_product_id', 'facility_product_id');
    }
}

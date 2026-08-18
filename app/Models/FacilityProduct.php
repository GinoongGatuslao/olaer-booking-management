<?php

namespace App\Models;

use App\FacilityCapacityPolicy;
use App\FacilityProductCode;
use App\FacilitySchedulePolicy;
use Database\Factories\FacilityProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property FacilityProductCode $product_code
 * @property FacilitySchedulePolicy $schedule_policy
 * @property FacilityCapacityPolicy $capacity_policy
 * @property int|null $suggested_minimum
 * @property int|null $suggested_maximum
 * @property int|null $included_guest_count
 * @property int|null $strict_maximum
 * @property bool $is_active
 */
class FacilityProduct extends Model
{
    /** @use HasFactory<FacilityProductFactory> */
    use HasFactory;

    protected $table = 'tbl_facility_product';

    protected $primaryKey = 'facility_product_id';

    protected $fillable = [
        'product_code',
        'facility_type_id',
        'display_name',
        'size_label',
        'schedule_policy',
        'capacity_policy',
        'suggested_minimum',
        'suggested_maximum',
        'included_guest_count',
        'strict_maximum',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::updating(function (FacilityProduct $product): void {
            if ($product->isDirty('product_code')) {
                throw new LogicException('Facility product codes cannot be changed.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'product_code' => FacilityProductCode::class,
            'schedule_policy' => FacilitySchedulePolicy::class,
            'capacity_policy' => FacilityCapacityPolicy::class,
            'suggested_minimum' => 'integer',
            'suggested_maximum' => 'integer',
            'included_guest_count' => 'integer',
            'strict_maximum' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<FacilityType, $this> */
    public function facilityType(): BelongsTo
    {
        return $this->belongsTo(FacilityType::class, 'facility_type_id', 'facility_type_id');
    }

    /** @return HasMany<Facility, $this> */
    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'facility_product_id', 'facility_product_id');
    }

    /** @return HasMany<ProductRate, $this> */
    public function productRates(): HasMany
    {
        return $this->hasMany(ProductRate::class, 'facility_product_id', 'facility_product_id');
    }
}

<?php

namespace App\Models;

use App\FacilityRateCode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $facility_product_id
 * @property int $quantity
 * @property FacilityRateCode $rate_code
 * @property CarbonInterface $check_in_date
 * @property CarbonInterface $check_out_date
 * @property int $estimated_users
 * @property-read FacilityProduct $facilityProduct
 */
class FacilityRequirementGroup extends Model
{
    protected $table = 'tbl_facility_requirement_groups';

    protected $primaryKey = 'facility_requirement_group_id';

    protected $fillable = [
        'facility_requirement_intent_id',
        'facility_product_id',
        'quantity',
        'rate_code',
        'check_in_date',
        'check_out_date',
        'estimated_users',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'rate_code' => FacilityRateCode::class,
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'estimated_users' => 'integer',
        ];
    }

    /** @return BelongsTo<FacilityRequirementIntent, $this> */
    public function intent(): BelongsTo
    {
        return $this->belongsTo(
            FacilityRequirementIntent::class,
            'facility_requirement_intent_id',
            'facility_requirement_intent_id',
        );
    }

    /** @return BelongsTo<FacilityProduct, $this> */
    public function facilityProduct(): BelongsTo
    {
        return $this->belongsTo(FacilityProduct::class, 'facility_product_id', 'facility_product_id');
    }
}

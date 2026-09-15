<?php

namespace App\Models;

use App\FacilityCapacityPolicy;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReservationDetail extends Model
{
    use HasFactory;

    protected $table = 'tbl_reservation_details';

    protected $primaryKey = 'reservation_details_id';

    public $timestamps = false;

    protected $fillable = [
        'reservation_id',
        'facility_id',
        'facility_product_id',
        'guest_count',
        'capacity_policy',
        'included_guest_count_snapshot',
        'strict_maximum_snapshot',
        'suggested_minimum_snapshot',
        'suggested_maximum_snapshot',
        'schedule_policy',
        'rate_code',
        'unit_rate',
        'rate_type',
        'check_in_date',
        'check_out_date',
        'discount_id',
        'base_price',
        'discount_rate',
        'discount_amount',
        'extra_guest_fee',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'guest_count' => 'integer',
            'capacity_policy' => FacilityCapacityPolicy::class,
            'included_guest_count_snapshot' => 'integer',
            'strict_maximum_snapshot' => 'integer',
            'suggested_minimum_snapshot' => 'integer',
            'suggested_maximum_snapshot' => 'integer',
            'schedule_policy' => FacilitySchedulePolicy::class,
            'rate_code' => FacilityRateCode::class,
            'unit_rate' => 'decimal:2',
            'base_price' => 'decimal:2',
            'discount_rate' => 'decimal:6',
            'discount_amount' => 'decimal:2',
            'extra_guest_fee' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id', 'reservation_id');
    }

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }

    /** @return BelongsTo<FacilityProduct, $this> */
    public function facilityProduct(): BelongsTo
    {
        return $this->belongsTo(FacilityProduct::class, 'facility_product_id', 'facility_product_id');
    }

    /** @return BelongsTo<Discount, $this> */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discount_id', 'discount_id');
    }

    /** @return HasMany<ReservationExtraGuest, $this> */
    public function extraGuests(): HasMany
    {
        return $this->hasMany(ReservationExtraGuest::class, 'reservation_details_id', 'reservation_details_id');
    }
}

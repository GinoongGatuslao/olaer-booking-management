<?php

namespace App\Models;

use App\FacilityCapacityPolicy;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingDetail extends Model
{
    use HasFactory;

    protected $table = 'tbl_booking_details';

    protected $primaryKey = 'booking_details_id';

    public $timestamps = false;

    /**
     * Booking stay fields are database DATE columns.
     *
     * Using a date-only persistence format keeps SQLite test storage aligned
     * with MySQL DATE storage instead of serializing casts as midnight
     * datetimes such as 2026-07-29 00:00:00.
     */
    protected $dateFormat = 'Y-m-d';

    protected $fillable = [
        'booking_id',
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
        'check_in_time',
        'status',
        'discount_id',
        'discount_rate',
        'user_id',
        'base_price',
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
            'discount_rate' => 'decimal:6',
            'base_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'extra_guest_fee' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** @return HasMany<BookingExtraGuest, $this> */
    public function extraGuests(): HasMany
    {
        return $this->hasMany(BookingExtraGuest::class, 'booking_details_id', 'booking_details_id');
    }
}

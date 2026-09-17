<?php

namespace App\Models;

use App\FacilityScheduleSlot;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $facility_schedule_block_id
 * @property int $facility_id
 * @property Carbon $service_date
 * @property FacilityScheduleSlot $slot
 * @property int|null $reservation_detail_id
 * @property int|null $booking_detail_id
 */
class FacilityScheduleBlock extends Model
{
    protected $table = 'tbl_facility_schedule_blocks';

    protected $primaryKey = 'facility_schedule_block_id';

    protected $fillable = [
        'facility_id',
        'service_date',
        'slot',
        'reservation_detail_id',
        'booking_detail_id',
    ];

    protected function casts(): array
    {
        return [
            'slot' => FacilityScheduleSlot::class,
        ];
    }

    /** @return Attribute<Carbon, DateTimeInterface|string> */
    protected function serviceDate(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): Carbon => Carbon::parse($value),
            set: fn (DateTimeInterface|string $value): string => Carbon::parse($value)->toDateString(),
        );
    }

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }

    /** @return BelongsTo<ReservationDetail, $this> */
    public function reservationDetail(): BelongsTo
    {
        return $this->belongsTo(ReservationDetail::class, 'reservation_detail_id', 'reservation_details_id');
    }

    /** @return BelongsTo<BookingDetail, $this> */
    public function bookingDetail(): BelongsTo
    {
        return $this->belongsTo(BookingDetail::class, 'booking_detail_id', 'booking_details_id');
    }
}

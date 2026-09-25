<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $party_count
 * @property string $status
 * @property int|null $reservation_id
 * @property int|null $booking_id
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $fulfilled_at
 */
class FacilityRequirementIntent extends Model
{
    protected $table = 'tbl_facility_requirement_intents';

    protected $primaryKey = 'facility_requirement_intent_id';

    protected $fillable = [
        'token',
        'session_id',
        'party_count',
        'status',
        'reservation_id',
        'booking_id',
        'expires_at',
        'fulfilled_at',
    ];

    protected $attributes = [
        'status' => 'Draft',
    ];

    protected function casts(): array
    {
        return [
            'party_count' => 'integer',
            'expires_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    /** @return HasMany<FacilityRequirementGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(
            FacilityRequirementGroup::class,
            'facility_requirement_intent_id',
            'facility_requirement_intent_id',
        );
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id', 'reservation_id');
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id', 'booking_id');
    }
}

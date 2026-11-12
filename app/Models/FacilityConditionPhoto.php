<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityConditionPhoto extends Model
{
    public const CURRENT = 'Current';

    public const IN_USE = 'In Use';

    public const OUTDATED = 'Outdated';

    protected $table = 'tbl_facility_condition_photo';

    protected $primaryKey = 'facility_condition_photo_id';

    protected $fillable = [
        'facility_id',
        'booking_details_id',
        'uploaded_by_user_id',
        'path',
        'original_name',
        'mime_type',
        'file_size',
        'status',
        'outdated_at',
    ];

    protected function casts(): array
    {
        return ['outdated_at' => 'datetime'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id', 'facility_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id', 'user_id');
    }
}

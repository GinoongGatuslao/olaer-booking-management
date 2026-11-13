<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityInspectionPhoto extends Model
{
    protected $table = 'tbl_facility_inspection_photo';

    /** The uploader's name is shown next to the upload time. */
    protected $with = ['uploadedBy'];

    protected $primaryKey = 'facility_inspection_photo_id';

    protected $fillable = [
        'facility_inspection_request_id',
        'booking_details_id',
        'uploaded_by_user_id',
        'path',
        'original_name',
        'mime_type',
        'file_size',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(FacilityInspectionRequest::class, 'facility_inspection_request_id', 'facility_inspection_request_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id', 'user_id');
    }
}

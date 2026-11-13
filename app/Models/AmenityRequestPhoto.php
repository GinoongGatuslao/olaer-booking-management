<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmenityRequestPhoto extends Model
{
    protected $table = 'tbl_amenity_request_photo';

    /** The uploader's name is shown next to the upload time. */
    protected $with = ['uploadedBy'];

    protected $primaryKey = 'amenity_request_photo_id';

    protected $fillable = [
        'amenity_request_id',
        'uploaded_by_user_id',
        'path',
        'original_name',
        'mime_type',
        'file_size',
    ];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id', 'user_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(AmenityRequest::class, 'amenity_request_id', 'amenity_request_id');
    }
}

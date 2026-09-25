<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionDraftFine extends Model
{
    protected $table = 'tbl_inspection_draft_fines';

    protected $primaryKey = 'inspection_draft_fine_id';

    protected $fillable = [
        'facility_inspection_request_id',
        'booking_details_id',
        'fine_id',
        'quantity',
        'item_source',
        'source_id',
        'unit_charge_snapshot',
        'total_charge',
        'remarks',
        'status',
        'guest_fine_id',
        'published_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'source_id' => 'integer',
            'unit_charge_snapshot' => 'decimal:2',
            'total_charge' => 'decimal:2',
            'published_at' => 'datetime',
        ];
    }

    public function inspectionRequest(): BelongsTo
    {
        return $this->belongsTo(FacilityInspectionRequest::class, 'facility_inspection_request_id', 'facility_inspection_request_id');
    }

    public function bookingDetail(): BelongsTo
    {
        return $this->belongsTo(BookingDetail::class, 'booking_details_id', 'booking_details_id');
    }

    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class, 'fine_id', 'fine_id');
    }

    public function publishedFine(): BelongsTo
    {
        return $this->belongsTo(GuestFine::class, 'guest_fine_id', 'guest_fine_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', 'user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id', 'user_id');
    }
}

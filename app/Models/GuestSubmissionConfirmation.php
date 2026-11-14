<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestSubmissionConfirmation extends Model
{
    protected $table = 'tbl_guest_submission_confirmation';

    protected $primaryKey = 'guest_submission_confirmation_id';

    protected $fillable = [
        'token_hash',
        'type',
        'email',
        'payload',
        'status',
        'expires_at',
        'confirmed_at',
        'reservation_id',
        'booking_id',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            // Guest details and payment info: encrypted at rest.
            'payload' => 'encrypted:array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}

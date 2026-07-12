<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPasswordResetOtp extends Model
{
    protected $table = 'tbl_user_password_reset_otp';

    protected $primaryKey = 'password_reset_otp_id';

    protected $fillable = [
        'user_id',
        'code_hash',
        'reset_token_hash',
        'attempts',
        'expires_at',
        'verified_at',
        'used_at',
    ];

    protected $hidden = [
        'code_hash',
        'reset_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}

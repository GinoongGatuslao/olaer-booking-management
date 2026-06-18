<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModeOfPayment extends Model
{
    use HasFactory;

    protected $table = 'tbl_mode_of_payment';

    protected $primaryKey = 'mode_of_payment_id';

    public $timestamps = false;

    protected $fillable = [
        'mode_of_payment',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'mode_of_payment_id', 'mode_of_payment_id');
    }
}

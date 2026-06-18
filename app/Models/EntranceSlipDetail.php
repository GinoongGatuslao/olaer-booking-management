<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntranceSlipDetail extends Model
{
    use HasFactory;

    protected $table = 'tbl_entrance_slip_details';

    protected $primaryKey = 'entrance_slip_details_id';

    public $timestamps = false;

    protected $fillable = [
        'entrance_slip_id',
        'entrance_fee_id',
        'guest_quantity',
        'discount_id',
        'discounted_quantity',
    ];

    protected function casts(): array
    {
        return [
            'guest_quantity' => 'integer',
            'discounted_quantity' => 'integer',
        ];
    }

    public function entranceSlip(): BelongsTo
    {
        return $this->belongsTo(EntranceSlip::class, 'entrance_slip_id', 'entrance_slip_id');
    }

    public function entranceFee(): BelongsTo
    {
        return $this->belongsTo(EntranceFee::class, 'entrance_fee_id', 'entrance_fee_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discount_id', 'discount_id');
    }
}

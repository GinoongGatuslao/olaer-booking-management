<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payments still "Pending" on a reservation that was already cancelled can
     * never be reviewed, so they become "Cancelled" (new cancellations do this
     * automatically from now on). payment_status is a plain string column, so no
     * schema change is needed.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tbl_payment') || ! Schema::hasTable('tbl_reservation')) {
            return;
        }

        DB::table('tbl_payment')
            ->whereRaw('LOWER(payment_status) = ?', ['pending'])
            ->whereIn('reservation_id', function ($query): void {
                $query->select('reservation_id')
                    ->from('tbl_reservation')
                    ->where('status', 'Cancelled');
            })
            ->update(['payment_status' => 'Cancelled']);
    }

    public function down(): void
    {
        // Not reversible: the original Pending rows cannot be told apart afterwards.
    }
};

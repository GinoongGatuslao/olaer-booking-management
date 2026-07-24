<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('tbl_amenity_request')
            || ! Schema::hasColumn(
                'tbl_amenity_request',
                'amenity_request_status',
            )
        ) {
            return;
        }

        DB::table('tbl_amenity_request')
            ->where('amenity_request_status', 'Awaiting Payment')
            ->update([
                'amenity_request_status' => 'Pending',
            ]);
    }

    public function down(): void
    {
        // This data migration is intentionally not reversible.
        // After the business-rule revision, Pending is the correct status for
        // undelivered amenity requests because payment is collected at checkout.
    }
};

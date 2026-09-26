<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_facility_inspection', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_facility_inspection', 'facility_inspection_request_id')) {
                $table->unsignedBigInteger('facility_inspection_request_id')
                    ->nullable()
                    ->after('booking_details_id');

                $table->foreign(
                    'facility_inspection_request_id',
                    'fk_inspection_request',
                )
                    ->references('facility_inspection_request_id')
                    ->on('tbl_facility_inspection_request')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tbl_facility_inspection', 'facility_inspection_request_id')) {
            return;
        }

        Schema::table('tbl_facility_inspection', function (Blueprint $table) {
            $table->dropForeign('fk_inspection_request');
            $table->dropColumn('facility_inspection_request_id');
        });
    }
};

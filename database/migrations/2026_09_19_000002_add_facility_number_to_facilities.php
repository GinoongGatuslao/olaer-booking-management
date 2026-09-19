<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_facility', function (Blueprint $table) {
            $table->string('facility_number', 50)
                ->nullable()
                ->after('facility_id')
                ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_facility', function (Blueprint $table) {
            $table->dropUnique('tbl_facility_facility_number_unique');
            $table->dropColumn('facility_number');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A resort facility label should identify one physical cottage, room, or function hall.
     * Without this, staff may accidentally create duplicate facility names and cause booking confusion.
     */
    public function up(): void
    {
        Schema::table('tbl_facility', function (Blueprint $table) {
            $table->unique('facility_name', 'tbl_facility_facility_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_facility', function (Blueprint $table) {
            $table->dropUnique('tbl_facility_facility_name_unique');
        });
    }
};

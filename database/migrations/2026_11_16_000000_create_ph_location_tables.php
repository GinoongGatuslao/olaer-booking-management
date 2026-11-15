<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference lists behind the address dropdowns (region, province, city/municipality,
     * barangay). tbl_address keeps storing plain text, so nothing links to these tables.
     * Fill them with:  php artisan locations:import
     * Source: https://github.com/wilfredpine/philippine-address-selector (ph-json folder).
     */
    public function up(): void
    {
        if (! Schema::hasTable('tbl_ph_region')) {
            Schema::create('tbl_ph_region', function (Blueprint $table): void {
                $table->string('region_code', 20)->primary();
                $table->string('name', 100);
            });
        }

        if (! Schema::hasTable('tbl_ph_province')) {
            Schema::create('tbl_ph_province', function (Blueprint $table): void {
                $table->string('province_code', 20)->primary();
                $table->string('region_code', 20)->index();
                $table->string('name', 100)->index();
            });
        }

        if (! Schema::hasTable('tbl_ph_city')) {
            Schema::create('tbl_ph_city', function (Blueprint $table): void {
                $table->string('city_code', 20)->primary();
                $table->string('province_code', 20)->index();
                $table->string('name', 100)->index();
            });
        }

        if (! Schema::hasTable('tbl_ph_barangay')) {
            Schema::create('tbl_ph_barangay', function (Blueprint $table): void {
                $table->string('brgy_code', 20)->primary();
                $table->string('city_code', 20)->index();
                $table->string('name', 100);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_ph_barangay');
        Schema::dropIfExists('tbl_ph_city');
        Schema::dropIfExists('tbl_ph_province');
        Schema::dropIfExists('tbl_ph_region');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Regions are no longer part of the address dropdowns. Addresses are stored as
     * plain text, so only the reference lists change: tbl_ph_region goes away and
     * provinces lose their region_code. `php artisan locations:import` reloads the
     * lists at any time.
     */
    public function up(): void
    {
        if (Schema::hasTable('tbl_ph_province') && Schema::hasColumn('tbl_ph_province', 'region_code')) {
            if (Schema::hasIndex('tbl_ph_province', ['region_code'])) {
                Schema::table('tbl_ph_province', function (Blueprint $table): void {
                    $table->dropIndex(['region_code']);
                });
            }

            Schema::table('tbl_ph_province', function (Blueprint $table): void {
                $table->dropColumn('region_code');
            });
        }

        Schema::dropIfExists('tbl_ph_region');
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_ph_region')) {
            Schema::create('tbl_ph_region', function (Blueprint $table): void {
                $table->string('region_code', 20)->primary();
                $table->string('name', 100);
            });
        }

        if (Schema::hasTable('tbl_ph_province') && ! Schema::hasColumn('tbl_ph_province', 'region_code')) {
            Schema::table('tbl_ph_province', function (Blueprint $table): void {
                $table->string('region_code', 20)->nullable()->index();
            });
        }
    }
};

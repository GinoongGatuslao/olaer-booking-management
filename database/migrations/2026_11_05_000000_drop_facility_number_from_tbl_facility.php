<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * facility_number was only an upper-cased copy of facility_name. The facility name
     * (already unique) is now the one identifier.
     */
    public function up(): void
    {
        if (Schema::hasIndex('tbl_facility', 'tbl_facility_facility_number_unique')) {
            Schema::table('tbl_facility', function (Blueprint $table): void {
                $table->dropUnique('tbl_facility_facility_number_unique');
            });
        }

        if (Schema::hasColumn('tbl_facility', 'facility_number')) {
            Schema::table('tbl_facility', function (Blueprint $table): void {
                $table->dropColumn('facility_number');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tbl_facility', 'facility_number')) {
            Schema::table('tbl_facility', function (Blueprint $table): void {
                $table->string('facility_number', 50)->nullable()->after('facility_id');
            });
        }

        // Restore the old behaviour: the number mirrors the upper-cased name.
        DB::table('tbl_facility')->update([
            'facility_number' => DB::raw('UPPER(facility_name)'),
        ]);

        if (! Schema::hasIndex('tbl_facility', 'tbl_facility_facility_number_unique')) {
            Schema::table('tbl_facility', function (Blueprint $table): void {
                $table->unique('facility_number', 'tbl_facility_facility_number_unique');
            });
        }
    }
};

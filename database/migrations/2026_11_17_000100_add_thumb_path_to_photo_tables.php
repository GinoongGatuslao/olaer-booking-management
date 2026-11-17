<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables that hold uploaded photos, each gets an optional small thumbnail. */
    private const TABLES = [
        'tbl_facility_condition_photo',
        'tbl_facility_inspection_photo',
        'tbl_amenity_request_photo',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'thumb_path')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('thumb_path', 255)->nullable()->after('path');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'thumb_path')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('thumb_path');
            });
        }
    }
};

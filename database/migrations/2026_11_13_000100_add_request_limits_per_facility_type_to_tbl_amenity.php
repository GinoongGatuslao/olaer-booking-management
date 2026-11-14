<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One request limit per facility type (cottage, room, function hall).
     * Each starts at the amenity's old single limit, so nothing changes until
     * an admin edits them.
     */
    private const COLUMNS = [
        'request_limit_cottage',
        'request_limit_room',
        'request_limit_function_hall',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('tbl_amenity')) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('tbl_amenity', $column)) {
                continue;
            }

            Schema::table('tbl_amenity', function (Blueprint $table) use ($column): void {
                $table->unsignedSmallInteger($column)->default(10)->after('request_limit');
            });

            if (Schema::hasColumn('tbl_amenity', 'request_limit')) {
                DB::table('tbl_amenity')->update([$column => DB::raw('request_limit')]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('tbl_amenity', $column)) {
                Schema::table('tbl_amenity', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};

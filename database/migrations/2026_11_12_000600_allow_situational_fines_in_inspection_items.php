<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A situational fine is not tied to a checklist item or an amenity, so its
     * row in tbl_facility_inspection_items has no item_source, source_id or
     * amenity_id. Those columns were NOT NULL, which made finishing an
     * inspection with a situational fine fail with:
     * "Column 'item_source' cannot be null".
     */
    public function up(): void
    {
        $table = 'tbl_facility_inspection_items';

        if (! Schema::hasTable($table)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // Plain MODIFY keeps the amenity foreign key and the indexes intact.
            DB::statement("ALTER TABLE `{$table}` MODIFY `item_source` VARCHAR(40) NULL");
            DB::statement("ALTER TABLE `{$table}` MODIFY `source_id` BIGINT UNSIGNED NULL");
            DB::statement("ALTER TABLE `{$table}` MODIFY `amenity_id` BIGINT UNSIGNED NULL");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->string('item_source', 40)->nullable()->change();
            $blueprint->unsignedBigInteger('source_id')->nullable()->change();
            $blueprint->unsignedBigInteger('amenity_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not reversible: rows for situational fines have no source or amenity,
        // so the columns cannot be made NOT NULL again without deleting them.
    }
};

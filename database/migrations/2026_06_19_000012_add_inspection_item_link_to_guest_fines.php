<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_guest_fine') && Schema::hasTable('tbl_facility_inspection_items') && ! Schema::hasColumn('tbl_guest_fine', 'facility_inspection_item_id')) {
            Schema::table('tbl_guest_fine', function (Blueprint $table): void {
                $table->foreignId('facility_inspection_item_id')
                    ->nullable()
                    ->after('reported_by_user_id')
                    ->constrained('tbl_facility_inspection_items', 'facility_inspection_item_id')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_guest_fine') && Schema::hasColumn('tbl_guest_fine', 'facility_inspection_item_id')) {
            Schema::table('tbl_guest_fine', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('facility_inspection_item_id');
            });
        }
    }
};

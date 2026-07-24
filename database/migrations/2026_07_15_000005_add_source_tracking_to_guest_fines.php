<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_guest_fine', function (Blueprint $table): void {
            if (! Schema::hasColumn('tbl_guest_fine', 'booking_details_id')) {
                $table->unsignedBigInteger('booking_details_id')->nullable()->after('booking_id');
            }

            if (! Schema::hasColumn('tbl_guest_fine', 'item_source')) {
                $table->string('item_source', 50)->nullable()->after('facility_id');
            }

            if (! Schema::hasColumn('tbl_guest_fine', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('item_source');
            }

            $table->index(
                [
                    'booking_id',
                    'booking_details_id',
                    'facility_id',
                    'fine_id',
                    'item_source',
                    'source_id',
                ],
                'idx_guest_fine_source_identity',
            );
        });
    }

    public function down(): void
    {
        Schema::table('tbl_guest_fine', function (Blueprint $table): void {
            $table->dropIndex('idx_guest_fine_source_identity');

            if (Schema::hasColumn('tbl_guest_fine', 'source_id')) {
                $table->dropColumn('source_id');
            }

            if (Schema::hasColumn('tbl_guest_fine', 'item_source')) {
                $table->dropColumn('item_source');
            }

            if (Schema::hasColumn('tbl_guest_fine', 'booking_details_id')) {
                $table->dropColumn('booking_details_id');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_amenity_request')) {
            return;
        }

        Schema::table('tbl_amenity_request', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_amenity_request', 'undelivered_at')) {
                $table->timestamp('undelivered_at')->nullable()->after('cancelled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tbl_amenity_request', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_amenity_request', 'undelivered_at')) {
                $table->dropColumn('undelivered_at');
            }
        });
    }
};

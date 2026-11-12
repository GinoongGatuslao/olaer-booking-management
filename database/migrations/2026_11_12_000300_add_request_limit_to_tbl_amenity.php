<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The most units of an amenity that can be requested for one facility of a
     * booking (across its non-cancelled requests). Defaults to 10; an admin can
     * change it per amenity.
     */
    public function up(): void
    {
        if (Schema::hasTable('tbl_amenity') && ! Schema::hasColumn('tbl_amenity', 'request_limit')) {
            Schema::table('tbl_amenity', function (Blueprint $table): void {
                $table->unsignedSmallInteger('request_limit')->default(10)->after('amenity_price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_amenity', 'request_limit')) {
            Schema::table('tbl_amenity', function (Blueprint $table): void {
                $table->dropColumn('request_limit');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_amenity_request', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_amenity_request', 'assigned_to_user_id')) {
                $table->foreignId('assigned_to_user_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('tbl_user', 'user_id')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('tbl_amenity_request', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('assigned_to_user_id');
            }

            if (! Schema::hasColumn('tbl_amenity_request', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('delivered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tbl_amenity_request', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_amenity_request', 'assigned_to_user_id')) {
                $table->dropConstrainedForeignId('assigned_to_user_id');
            }

            if (Schema::hasColumn('tbl_amenity_request', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }

            if (Schema::hasColumn('tbl_amenity_request', 'cancelled_at')) {
                $table->dropColumn('cancelled_at');
            }
        });
    }
};

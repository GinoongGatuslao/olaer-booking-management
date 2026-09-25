<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_facility_requirement_intents', function (Blueprint $table) {
            $table->foreignId('booking_id')
                ->nullable()
                ->unique()
                ->after('reservation_id')
                ->constrained('tbl_booking', 'booking_id')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_facility_requirement_intents', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
            $table->dropUnique('tbl_facility_requirement_intents_booking_id_unique');
            $table->dropColumn('booking_id');
        });
    }
};

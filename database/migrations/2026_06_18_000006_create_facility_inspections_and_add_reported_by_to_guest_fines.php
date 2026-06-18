<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_facility_inspection', function (Blueprint $table) {
            $table->id('facility_inspection_id');
            $table->foreignId('booking_details_id')->constrained('tbl_booking_details', 'booking_details_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('tbl_booking', 'booking_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained('tbl_facility', 'facility_id')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('inspected_by_user_id')->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('inspection_status', 30)->default('Cleared'); // Cleared / Damage Found
            $table->text('remarks')->nullable();
            $table->timestamp('inspected_at');
            $table->timestamps();

            $table->unique('booking_details_id', 'facility_inspection_booking_detail_unique');
        });

        Schema::table('tbl_guest_fine', function (Blueprint $table) {
            $table->foreignId('reported_by_user_id')
                ->nullable()
                ->after('date_checked')
                ->constrained('tbl_user', 'user_id')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_guest_fine', function (Blueprint $table) {
            $table->dropForeign(['reported_by_user_id']);
            $table->dropColumn('reported_by_user_id');
        });

        Schema::dropIfExists('tbl_facility_inspection');
    }
};

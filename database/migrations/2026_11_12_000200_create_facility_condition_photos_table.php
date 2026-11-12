<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Condition photos maintenance staff take of a facility while no guest is
     * checked in. status: Current (ready for the next guest) -> In Use (attached
     * to the stay that checked in) -> Outdated (that stay ended; new photos needed).
     */
    public function up(): void
    {
        if (Schema::hasTable('tbl_facility_condition_photo')) {
            return;
        }

        Schema::create('tbl_facility_condition_photo', function (Blueprint $table): void {
            $table->id('facility_condition_photo_id');
            $table->unsignedBigInteger('facility_id')->index();
            $table->unsignedBigInteger('booking_details_id')->nullable()->index();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->string('path', 255);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0); // bytes
            $table->string('status', 20)->default('Current'); // Current / In Use / Outdated
            $table->timestamp('outdated_at')->nullable();
            $table->timestamps();

            $table->index(['facility_id', 'status'], 'fcp_facility_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_facility_condition_photo');
    }
};

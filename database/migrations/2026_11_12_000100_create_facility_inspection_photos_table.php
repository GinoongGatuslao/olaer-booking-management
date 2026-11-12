<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photos a maintenance staff member attaches to an inspection as proof of
     * damage. Files live on the private disk; only the path is stored here.
     * Like the other inspection tables, no foreign keys (custom-schema project).
     */
    public function up(): void
    {
        if (Schema::hasTable('tbl_facility_inspection_photo')) {
            return;
        }

        Schema::create('tbl_facility_inspection_photo', function (Blueprint $table): void {
            $table->id('facility_inspection_photo_id');
            $table->unsignedBigInteger('facility_inspection_request_id')->index();
            $table->unsignedBigInteger('booking_details_id')->index();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable()->index();
            $table->string('path', 255);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0); // bytes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_facility_inspection_photo');
    }
};

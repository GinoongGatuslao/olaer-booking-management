<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Proof-of-delivery photos maintenance staff attach to an amenity request. */
    public function up(): void
    {
        if (Schema::hasTable('tbl_amenity_request_photo')) {
            return;
        }

        Schema::create('tbl_amenity_request_photo', function (Blueprint $table): void {
            $table->id('amenity_request_photo_id');
            $table->unsignedBigInteger('amenity_request_id')->index();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->string('path', 255);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0); // bytes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_amenity_request_photo');
    }
};

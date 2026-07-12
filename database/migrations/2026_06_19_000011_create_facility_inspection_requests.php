<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This table is small workflow glue: cashier asks maintenance to inspect one checked-in booking detail.
        // No foreign constraints are added here to avoid breaking the current custom-schema project while we stabilize the flow.
        if (Schema::hasTable('tbl_facility_inspection_request')) {
            return;
        }

        Schema::create('tbl_facility_inspection_request', function (Blueprint $table): void {
            $table->id('facility_inspection_request_id');
            $table->unsignedBigInteger('booking_id')->index();
            $table->unsignedBigInteger('booking_details_id')->index();
            $table->unsignedBigInteger('facility_id')->index();
            $table->unsignedBigInteger('requested_by_user_id')->index();
            $table->unsignedBigInteger('assigned_to_user_id')->nullable()->index();
            $table->string('status', 30)->default('Pending'); // Pending / In Progress / Completed / Cancelled
            $table->text('request_notes')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'requested_at'], 'fir_status_requested_idx');
            $table->index(['booking_details_id', 'status'], 'fir_detail_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_facility_inspection_request');
    }
};

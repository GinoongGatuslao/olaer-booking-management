<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_reservation', function (Blueprint $table) {
            $table->id('reservation_id');
            $table->string('r_ref_no', 20)->unique();
            $table->foreignId('guest_id')->constrained('tbl_guest', 'guest_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('reservation_date');
            $table->decimal('total_price', 10, 2)->default(0);
            $table->decimal('amount_due', 10, 2)->default(0);
            $table->unsignedInteger('no_of_extra_guests')->default(0)->nullable();
            // Nullable because guests can create reservations from the public website without cashier assistance.
            $table->foreignId('user_id')->nullable()->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
            $table->string('status', 50)->default('Active');
            $table->string('cancellation_reason', 255)->nullable();
            $table->date('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tbl_reservation_details', function (Blueprint $table) {
            $table->id('reservation_details_id');
            $table->foreignId('reservation_id')->constrained('tbl_reservation', 'reservation_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained('tbl_facility', 'facility_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('rate_type', 20);
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->foreignId('discount_id')->nullable()->constrained('tbl_discount', 'discount_id')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::create('tbl_reservation_extra_guests', function (Blueprint $table) {
            $table->id('reservation_extra_guest_id');
            $table->foreignId('reservation_id')->constrained('tbl_reservation', 'reservation_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('first_name', 50);
            $table->string('middle_name', 50)->nullable();
            $table->string('last_name', 50);
        });

        Schema::create('tbl_entrance_slip', function (Blueprint $table) {
            $table->id('entrance_slip_id');
            $table->unsignedInteger('no_of_adult')->default(0);
            $table->unsignedInteger('no_of_children')->default(0);
            $table->unsignedInteger('no_of_PWD_SC')->default(0);
            $table->unsignedInteger('no_of_Male')->default(0);
            $table->unsignedInteger('no_of_Female')->default(0);
            $table->unsignedInteger('no_of_Tourist')->default(0);
            $table->foreignId('created_by_user_id')->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained('tbl_guest', 'guest_id')->cascadeOnUpdate()->nullOnDelete();
            $table->date('date_created');
            $table->time('time_created');
            $table->decimal('total_price', 10, 2)->default(0);
            $table->decimal('amount_due', 10, 2)->default(0);
            $table->foreignId('handled_by_user_id')->nullable()->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
            $table->string('status', 50)->default('Unpaid');
            $table->timestamps();
        });

        Schema::create('tbl_entrance_slip_details', function (Blueprint $table) {
            $table->id('entrance_slip_details_id');
            $table->foreignId('entrance_slip_id')->constrained('tbl_entrance_slip', 'entrance_slip_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('entrance_fee_id')->constrained('tbl_entrance_fee', 'entrance_fee_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('guest_quantity');
            $table->foreignId('discount_id')->nullable()->constrained('tbl_discount', 'discount_id')->cascadeOnUpdate()->nullOnDelete();
            $table->unsignedInteger('discounted_quantity')->nullable()->default(0);
        });

        Schema::create('tbl_booking', function (Blueprint $table) {
            $table->id('booking_id');
            $table->string('b_ref_no', 20)->unique();
            $table->foreignId('guest_id')->constrained('tbl_guest', 'guest_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('booking_date');
            $table->unsignedInteger('no_of_extra_guests')->default(0)->nullable();
            $table->decimal('total_price', 10, 2)->default(0);
            $table->decimal('amount_due', 10, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('tbl_reservation', 'reservation_id')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('entrance_slip_id')->nullable()->constrained('tbl_entrance_slip', 'entrance_slip_id')->cascadeOnUpdate()->nullOnDelete();
            $table->string('status', 50)->default('Active');
            $table->timestamps();
        });

        Schema::create('tbl_booking_extra_guests', function (Blueprint $table) {
            $table->id('booking_extra_guest_id');
            $table->foreignId('booking_id')->constrained('tbl_booking', 'booking_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('first_name', 50);
            $table->string('middle_name', 50)->nullable();
            $table->string('last_name', 50);
        });

        Schema::create('tbl_booking_details', function (Blueprint $table) {
            $table->id('booking_details_id');
            $table->foreignId('booking_id')->constrained('tbl_booking', 'booking_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('facility_id')->nullable()->constrained('tbl_facility', 'facility_id')->cascadeOnUpdate()->nullOnDelete();
            $table->string('rate_type', 20);
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->time('check_in_time')->nullable();
            $table->string('status', 20)->default('Active');
            $table->foreignId('discount_id')->nullable()->constrained('tbl_discount', 'discount_id')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::create('tbl_guest_fine', function (Blueprint $table) {
            $table->id('guest_fine_id');
            $table->foreignId('booking_id')->constrained('tbl_booking', 'booking_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('fine_id')->constrained('tbl_fine', 'fine_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->foreignId('facility_id')->nullable()->constrained('tbl_facility', 'facility_id')->cascadeOnUpdate()->nullOnDelete();
            $table->decimal('total_charge', 10, 2)->default(0);
            $table->date('date_checked');
            $table->timestamps();
        });

        Schema::create('tbl_amenity_request', function (Blueprint $table) {
            $table->id('amenity_request_id');
            $table->foreignId('booking_id')->constrained('tbl_booking', 'booking_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('amenity_request_status', 20)->default('Pending');
            $table->decimal('total_price', 10, 2)->default(0);
            $table->date('date_created');
            $table->foreignId('user_id')->nullable()->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tbl_amenity_request_details', function (Blueprint $table) {
            // Eloquent needs one primary key. Keep a unique group to prevent duplicate amenity request detail rows.
            $table->id('amenity_request_detail_id');
            $table->foreignId('amenity_request_id')->constrained('tbl_amenity_request', 'amenity_request_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained('tbl_facility', 'facility_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('amenity_id')->constrained('tbl_amenity', 'amenity_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('amenity_quantity')->default(1);
            $table->unique(['amenity_request_id', 'facility_id', 'amenity_id'], 'amenity_request_detail_unique');
        });

        Schema::create('tbl_mode_of_payment', function (Blueprint $table) {
            $table->id('mode_of_payment_id');
            $table->string('mode_of_payment', 50)->unique();
        });

        Schema::create('tbl_payment', function (Blueprint $table) {
            $table->id('payment_id');
            $table->string('p_ref_no', 20)->unique();
            $table->foreignId('booking_id')->nullable()->constrained('tbl_booking', 'booking_id')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('tbl_reservation', 'reservation_id')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('entrance_slip_id')->nullable()->constrained('tbl_entrance_slip', 'entrance_slip_id')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('mode_of_payment_id')->constrained('tbl_mode_of_payment', 'mode_of_payment_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('reference_number', 50)->nullable();
            $table->string('proof_of_payment_path', 255)->nullable();
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->date('date_paid');
            $table->foreignId('user_id')->nullable()->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
            // Useful for GCash proof uploads: uploaded proof is pending until verified by staff.
            $table->string('payment_status', 20)->default('Verified'); // Pending / Verified / Rejected
            $table->foreignId('verified_by_user_id')->nullable()->constrained('tbl_user', 'user_id')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_payment');
        Schema::dropIfExists('tbl_mode_of_payment');
        Schema::dropIfExists('tbl_amenity_request_details');
        Schema::dropIfExists('tbl_amenity_request');
        Schema::dropIfExists('tbl_guest_fine');
        Schema::dropIfExists('tbl_booking_details');
        Schema::dropIfExists('tbl_booking_extra_guests');
        Schema::dropIfExists('tbl_booking');
        Schema::dropIfExists('tbl_entrance_slip_details');
        Schema::dropIfExists('tbl_entrance_slip');
        Schema::dropIfExists('tbl_reservation_extra_guests');
        Schema::dropIfExists('tbl_reservation_details');
        Schema::dropIfExists('tbl_reservation');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_amenity_name', function (Blueprint $table) {
            $table->id('amenity_name_id');
            $table->string('amenity_name', 50)->unique();
        });

        Schema::create('tbl_amenity', function (Blueprint $table) {
            $table->id('amenity_id');
            $table->foreignId('amenity_name_id')->constrained('tbl_amenity_name', 'amenity_name_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('amenity_description', 50);
            $table->string('amenity_type', 50); // Rentable / Inclusive
            $table->decimal('amenity_price', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('tbl_entrance_fee', function (Blueprint $table) {
            $table->id('entrance_fee_id');
            $table->string('entrance_fee_name', 50)->unique();
            $table->decimal('entrance_fee_price', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('tbl_damage_type', function (Blueprint $table) {
            $table->id('damage_type_id');
            $table->string('damage_type', 50)->unique();
        });

        Schema::create('tbl_fine', function (Blueprint $table) {
            $table->id('fine_id');
            $table->string('fine_type', 50); // Amenity / Situational
            $table->foreignId('amenity_id')->nullable()->constrained('tbl_amenity', 'amenity_id')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('damage_type_id')->nullable()->constrained('tbl_damage_type', 'damage_type_id')->cascadeOnUpdate()->nullOnDelete();
            $table->string('situational_fine', 50)->nullable();
            $table->string('situational_fine_description', 100)->nullable();
            $table->decimal('fine_charge', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('tbl_facility_type', function (Blueprint $table) {
            $table->id('facility_type_id');
            $table->string('facility_type', 50)->unique(); // Cottage / Room / Function Hall
        });

        Schema::create('tbl_facility', function (Blueprint $table) {
            $table->id('facility_id');
            $table->string('facility_name', 50);
            $table->foreignId('facility_type_id')->constrained('tbl_facility_type', 'facility_type_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('facility_size', 50);
            $table->string('facility_status', 50)->default('Available');
            $table->string('capacity', 50);
            $table->timestamps();
        });

        Schema::create('tbl_facility_amenities', function (Blueprint $table) {
            // Eloquent needs one primary key. Keep a unique pair to prevent duplicate amenity rows per facility.
            $table->id('facility_amenity_id');
            $table->foreignId('facility_id')->constrained('tbl_facility', 'facility_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained('tbl_amenity', 'amenity_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('amenity_quantity');
            $table->unique(['facility_id', 'amenity_id']);
        });

        Schema::create('tbl_facility_price', function (Blueprint $table) {
            // Eloquent needs one primary key. Keep a unique pair to prevent duplicate rate rows per facility.
            $table->id('facility_price_id');
            $table->foreignId('facility_id')->constrained('tbl_facility', 'facility_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('rate_type', 20); // Day Rate / Night Rate / Overnight / etc.
            $table->decimal('facility_price', 10, 2)->default(0);
            $table->unique(['facility_id', 'rate_type']);
        });

        Schema::create('tbl_discount', function (Blueprint $table) {
            $table->id('discount_id');
            $table->string('discount_name', 50);
            $table->decimal('discount_amount', 10, 2); // Percentage as whole number, e.g. 20 = 20%
            $table->boolean('app_to_adult')->default(false);
            $table->boolean('app_to_children')->default(false);
            $table->boolean('app_to_SC_PWD')->default(false);
            $table->boolean('app_to_cottage')->default(false);
            $table->boolean('app_to_room')->default(false);
            $table->boolean('app_to_function_hall')->default(false);
            $table->date('discount_start')->nullable();
            $table->date('discount_end')->nullable();
            $table->string('status', 50)->default('Inactive');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_discount');
        Schema::dropIfExists('tbl_facility_price');
        Schema::dropIfExists('tbl_facility_amenities');
        Schema::dropIfExists('tbl_facility');
        Schema::dropIfExists('tbl_facility_type');
        Schema::dropIfExists('tbl_fine');
        Schema::dropIfExists('tbl_damage_type');
        Schema::dropIfExists('tbl_entrance_fee');
        Schema::dropIfExists('tbl_amenity');
        Schema::dropIfExists('tbl_amenity_name');
    }
};

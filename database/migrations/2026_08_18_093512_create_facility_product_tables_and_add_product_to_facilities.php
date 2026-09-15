<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tbl_facility_product', function (Blueprint $table) {
            $table->id('facility_product_id');
            $table->string('product_code', 50)->unique();
            $table->foreignId('facility_type_id')->constrained('tbl_facility_type', 'facility_type_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('display_name', 100);
            $table->string('size_label', 50)->nullable();
            $table->string('schedule_policy', 30);
            $table->string('capacity_policy', 40);
            $table->unsignedSmallInteger('suggested_minimum')->nullable();
            $table->unsignedSmallInteger('suggested_maximum')->nullable();
            $table->unsignedSmallInteger('included_guest_count')->nullable();
            $table->unsignedSmallInteger('strict_maximum')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tbl_facility_product_rate', function (Blueprint $table) {
            $table->id('facility_product_rate_id');
            $table->foreignId('facility_product_id')->constrained('tbl_facility_product', 'facility_product_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('rate_code', 30);
            $table->string('display_name', 50);
            $table->decimal('amount', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['facility_product_id', 'rate_code']);
        });

        Schema::table('tbl_facility', function (Blueprint $table) {
            $table->foreignId('facility_product_id')
                ->nullable()
                ->after('facility_type_id')
                ->constrained('tbl_facility_product', 'facility_product_id')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_facility', function (Blueprint $table) {
            $table->dropForeign(['facility_product_id']);
            $table->dropColumn('facility_product_id');
        });

        Schema::dropIfExists('tbl_facility_product_rate');
        Schema::dropIfExists('tbl_facility_product');
    }
};

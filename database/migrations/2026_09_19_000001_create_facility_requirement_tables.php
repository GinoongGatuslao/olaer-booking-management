<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_facility_requirement_intents', function (Blueprint $table) {
            $table->id('facility_requirement_intent_id');
            $table->uuid('token')->unique();
            $table->string('session_id', 120)->index();
            $table->unsignedInteger('party_count');
            $table->string('status', 20)->default('Draft')->index();

            $table->foreignId('reservation_id')
                ->nullable()
                ->unique()
                ->constrained('tbl_reservation', 'reservation_id')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->timestamp('expires_at')->index();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tbl_facility_requirement_groups', function (Blueprint $table) {
            $table->id('facility_requirement_group_id');

            $table->foreignId('facility_requirement_intent_id');

            $table->foreign(
                'facility_requirement_intent_id',
                'fk_fac_req_group_intent'
            )
                ->references('facility_requirement_intent_id')
                ->on('tbl_facility_requirement_intents')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('facility_product_id')
                ->constrained('tbl_facility_product', 'facility_product_id')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->unsignedSmallInteger('quantity');
            $table->string('rate_code', 30);
            $table->date('check_in_date');
            $table->date('check_out_date');
            $table->unsignedInteger('estimated_users');
            $table->timestamps();

            $table->index(
                ['facility_product_id', 'check_in_date', 'check_out_date'],
                'idx_requirement_group_availability',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_facility_requirement_groups');
        Schema::dropIfExists('tbl_facility_requirement_intents');
    }
};
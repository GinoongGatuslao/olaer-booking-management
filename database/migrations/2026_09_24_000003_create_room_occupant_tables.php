<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_reservation_room_occupants', function (Blueprint $table) {
            $table->id('reservation_room_occupant_id');
            $table->foreignId('reservation_details_id')
                ->constrained('tbl_reservation_details', 'reservation_details_id')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('first_name', 50);
            $table->string('middle_name', 50)->nullable();
            $table->string('last_name', 50);
            $table->timestamps();

            $table->unique(
                ['reservation_details_id', 'position'],
                'uq_reservation_room_occupant_position',
            );
        });

        Schema::create('tbl_booking_room_occupants', function (Blueprint $table) {
            $table->id('booking_room_occupant_id');
            $table->foreignId('booking_details_id')
                ->constrained('tbl_booking_details', 'booking_details_id')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('first_name', 50);
            $table->string('middle_name', 50)->nullable();
            $table->string('last_name', 50);
            $table->timestamps();

            $table->unique(
                ['booking_details_id', 'position'],
                'uq_booking_room_occupant_position',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_booking_room_occupants');
        Schema::dropIfExists('tbl_reservation_room_occupants');
    }
};

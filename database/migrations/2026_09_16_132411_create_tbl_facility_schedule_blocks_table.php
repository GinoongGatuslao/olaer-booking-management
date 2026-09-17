<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_facility_schedule_blocks', function (Blueprint $table) {
            $table->id('facility_schedule_block_id');
            $table->foreignId('facility_id')
                ->constrained('tbl_facility', 'facility_id')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->date('service_date');
            $table->enum('slot', ['overnight', 'day', 'night', 'whole_day']);
            $table->foreignId('reservation_detail_id')
                ->nullable()
                ->index()
                ->constrained('tbl_reservation_details', 'reservation_details_id')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('booking_detail_id')
                ->nullable()
                ->index()
                ->constrained('tbl_booking_details', 'booking_details_id')
                ->restrictOnUpdate()
                ->restrictOnDelete();
            $table->timestamps();
            $table->unique(
                ['facility_id', 'service_date', 'slot'],
                'uq_facility_schedule_slot',
            );
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER chk_facility_schedule_block_owner_insert
                BEFORE INSERT ON tbl_facility_schedule_blocks
                WHEN (NEW.reservation_detail_id IS NULL) = (NEW.booking_detail_id IS NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'A facility schedule block must have exactly one owner.');
                END
                SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER chk_facility_schedule_block_owner_update
                BEFORE UPDATE OF reservation_detail_id, booking_detail_id ON tbl_facility_schedule_blocks
                WHEN (NEW.reservation_detail_id IS NULL) = (NEW.booking_detail_id IS NULL)
                BEGIN
                    SELECT RAISE(ABORT, 'A facility schedule block must have exactly one owner.');
                END
                SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE tbl_facility_schedule_blocks
            ADD CONSTRAINT chk_facility_schedule_block_owner
            CHECK ((reservation_detail_id IS NULL) <> (booking_detail_id IS NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_facility_schedule_blocks');
    }
};

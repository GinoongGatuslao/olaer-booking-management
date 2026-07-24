<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('tbl_booking')
            || ! Schema::hasColumn('tbl_booking', 'reservation_id')
        ) {
            return;
        }

        $duplicateExists = DB::table('tbl_booking')
            ->whereNotNull('reservation_id')
            ->select('reservation_id')
            ->groupBy('reservation_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicateExists) {
            throw new \RuntimeException(
                'Duplicate bookings linked to the same reservation exist. Resolve them before adding the single-conversion constraint.',
            );
        }

        Schema::table('tbl_booking', function (Blueprint $table): void {
            $table->unique(
                'reservation_id',
                'uq_booking_reservation_once',
            );
        });
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('tbl_booking')
            || ! Schema::hasColumn('tbl_booking', 'reservation_id')
        ) {
            return;
        }

        Schema::table('tbl_booking', function (Blueprint $table): void {
            $table->dropUnique(
                'uq_booking_reservation_once',
            );
        });
    }
};

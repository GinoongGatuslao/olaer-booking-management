<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_booking_details')) {
            Schema::table(
                'tbl_booking_details',
                function (Blueprint $table): void {
                    $table->index(
                        [
                            'facility_id',
                            'check_in_date',
                            'check_out_date',
                            'status',
                        ],
                        'idx_booking_details_facility_schedule',
                    );
                },
            );
        }

        if (Schema::hasTable('tbl_reservation_details')) {
            Schema::table(
                'tbl_reservation_details',
                function (Blueprint $table): void {
                    $table->index(
                        [
                            'facility_id',
                            'check_in_date',
                            'check_out_date',
                        ],
                        'idx_reservation_details_facility_schedule',
                    );
                },
            );
        }

        if (
            Schema::hasTable('tbl_booking')
            && Schema::hasColumn('tbl_booking', 'status')
        ) {
            Schema::table(
                'tbl_booking',
                function (Blueprint $table): void {
                    $table->index(
                        'status',
                        'idx_booking_status',
                    );
                },
            );
        }

        if (
            Schema::hasTable('tbl_reservation')
            && Schema::hasColumn('tbl_reservation', 'status')
        ) {
            Schema::table(
                'tbl_reservation',
                function (Blueprint $table): void {
                    $table->index(
                        'status',
                        'idx_reservation_status',
                    );
                },
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_booking_details')) {
            Schema::table(
                'tbl_booking_details',
                function (Blueprint $table): void {
                    $table->dropIndex(
                        'idx_booking_details_facility_schedule',
                    );
                },
            );
        }

        if (Schema::hasTable('tbl_reservation_details')) {
            Schema::table(
                'tbl_reservation_details',
                function (Blueprint $table): void {
                    $table->dropIndex(
                        'idx_reservation_details_facility_schedule',
                    );
                },
            );
        }

        if (Schema::hasTable('tbl_booking')) {
            Schema::table(
                'tbl_booking',
                function (Blueprint $table): void {
                    $table->dropIndex(
                        'idx_booking_status',
                    );
                },
            );
        }

        if (Schema::hasTable('tbl_reservation')) {
            Schema::table(
                'tbl_reservation',
                function (Blueprint $table): void {
                    $table->dropIndex(
                        'idx_reservation_status',
                    );
                },
            );
        }
    }
};

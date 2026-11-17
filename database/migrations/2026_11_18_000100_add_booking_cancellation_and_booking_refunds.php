<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bookings can now be cancelled (like reservations) and refunded.
     * A refund request belongs to either a reservation or a booking, so
     * reservation_id becomes nullable and booking_id is added. Like the other
     * custom tables, no foreign keys.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('tbl_booking', 'cancellation_reason')) {
            Schema::table('tbl_booking', function (Blueprint $table): void {
                $table->string('cancellation_reason', 500)->nullable();
                $table->date('cancelled_at')->nullable();
            });
        }

        if (! Schema::hasColumn('tbl_refund_request', 'booking_id')) {
            Schema::table('tbl_refund_request', function (Blueprint $table): void {
                $table->unsignedBigInteger('booking_id')->nullable()->index();
                $table->unique(['booking_id', 'attempt_no']);
            });
        }

        Schema::table('tbl_refund_request', function (Blueprint $table): void {
            $table->unsignedBigInteger('reservation_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_refund_request', 'booking_id')) {
            Schema::table('tbl_refund_request', function (Blueprint $table): void {
                $table->dropUnique(['booking_id', 'attempt_no']);
                $table->dropIndex(['booking_id']);
                $table->dropColumn('booking_id');
            });
        }

        if (Schema::hasColumn('tbl_booking', 'cancellation_reason')) {
            Schema::table('tbl_booking', function (Blueprint $table): void {
                $table->dropColumn(['cancellation_reason', 'cancelled_at']);
            });
        }
    }
};

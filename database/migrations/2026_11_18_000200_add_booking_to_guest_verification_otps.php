<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time codes can now belong to a booking as well as a reservation
     * (guest "Manage reservation or booking" page), so reservation_id becomes nullable.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('tbl_guest_verification_otp', 'booking_id')) {
            Schema::table('tbl_guest_verification_otp', function (Blueprint $table): void {
                $table->foreignId('booking_id')
                    ->nullable()
                    ->constrained('tbl_booking', 'booking_id')
                    ->cascadeOnUpdate()
                    ->cascadeOnDelete();
            });
        }

        Schema::table('tbl_guest_verification_otp', function (Blueprint $table): void {
            $table->unsignedBigInteger('reservation_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_guest_verification_otp', 'booking_id')) {
            Schema::table('tbl_guest_verification_otp', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('booking_id');
            });
        }
    }
};

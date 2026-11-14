<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guest's reservation or booking request waits here until they click the
     * link we email them. Only then is the reservation/booking created and the
     * payment recorded. The request itself (guest details, plan, payment info)
     * is stored encrypted in `payload`; the proof of payment stays on the
     * private disk. Like the other custom tables, no foreign keys.
     */
    public function up(): void
    {
        if (Schema::hasTable('tbl_guest_submission_confirmation')) {
            return;
        }

        Schema::create('tbl_guest_submission_confirmation', function (Blueprint $table): void {
            $table->id('guest_submission_confirmation_id');
            $table->char('token_hash', 64)->unique();
            $table->string('type', 20); // reservation | booking
            $table->string('email', 100)->index();
            $table->longText('payload')->nullable();
            $table->string('status', 20)->default('Pending')->index(); // Pending | Confirmed | Expired | Failed
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable()->index();
            $table->unsignedBigInteger('booking_id')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_guest_submission_confirmation');
    }
};

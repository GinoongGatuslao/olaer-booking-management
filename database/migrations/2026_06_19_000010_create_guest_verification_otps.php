<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_guest_verification_otp', function (Blueprint $table) {
            $table->id('guest_verification_otp_id');
            $table->foreignId('reservation_id')->constrained('tbl_reservation', 'reservation_id')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('email', 100);
            $table->string('purpose', 50)->default('reservation_manage');
            $table->string('otp_hash', 255);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['reservation_id', 'email', 'purpose'], 'guest_otp_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_guest_verification_otp');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_user_password_reset_otp', function (Blueprint $table) {
            $table->id('password_reset_otp_id');
            $table->foreignId('user_id')
                ->constrained('tbl_user', 'user_id')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->string('code_hash', 255);
            $table->string('reset_token_hash', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_user_password_reset_otp');
    }
};

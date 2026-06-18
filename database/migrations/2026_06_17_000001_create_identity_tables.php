<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_address', function (Blueprint $table) {
            $table->id('address_id');
            $table->string('purok', 50)->nullable();
            $table->string('province', 50);
            $table->string('city', 50);
            $table->string('barangay', 50)->nullable();
        });

        Schema::create('tbl_role', function (Blueprint $table) {
            $table->id('role_id');
            $table->string('role_name', 50)->unique();
        });

        Schema::create('tbl_user', function (Blueprint $table) {
            $table->id('user_id');
            $table->string('first_name', 50);
            $table->string('middle_name', 50)->nullable();
            $table->string('last_name', 50);
            $table->string('username', 50)->unique();
            $table->string('password', 255);
            $table->string('email', 50)->unique();
            $table->string('contact_no', 11);
            $table->string('status', 50)->default('Active');
            $table->foreignId('address_id')->constrained('tbl_address', 'address_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('role_id')->constrained('tbl_role', 'role_id')->cascadeOnUpdate()->restrictOnDelete();

            // Laravel authentication compatibility.
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('tbl_guest', function (Blueprint $table) {
            $table->id('guest_id');
            $table->string('first_name', 50);
            $table->string('middle_name', 50)->nullable();
            $table->string('last_name', 50);
            $table->string('contact_no', 11);
            $table->foreignId('address_id')->constrained('tbl_address', 'address_id')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('email', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_guest');
        Schema::dropIfExists('tbl_user');
        Schema::dropIfExists('tbl_role');
        Schema::dropIfExists('tbl_address');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * MySQL/MariaDB can silently give the first NOT NULL timestamp column of a table
     * "DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP". On this table that reset
     * expires_at to "now" every time an OTP row was updated (for example when the code
     * was verified), so a code looked expired seconds after it was created.
     * A DATETIME column has no such automatic behaviour.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE tbl_user_password_reset_otp MODIFY expires_at DATETIME NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tbl_user_password_reset_otp MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }
};

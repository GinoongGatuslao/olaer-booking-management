<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The one session that may currently use the account. Logging in on another device
     * replaces it, which signs the older device out on its next request.
     */
    public function up(): void
    {
        Schema::table('tbl_user', function (Blueprint $table): void {
            $table->string('current_session_id', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_user', function (Blueprint $table): void {
            $table->dropColumn('current_session_id');
        });
    }
};

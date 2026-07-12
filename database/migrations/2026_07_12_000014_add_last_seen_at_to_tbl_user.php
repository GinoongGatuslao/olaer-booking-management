<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tbl_user', 'last_seen_at')) {
            Schema::table('tbl_user', function (Blueprint $table): void {
                $table->timestamp('last_seen_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_user', 'last_seen_at')) {
            Schema::table('tbl_user', function (Blueprint $table): void {
                $table->dropColumn('last_seen_at');
            });
        }
    }
};

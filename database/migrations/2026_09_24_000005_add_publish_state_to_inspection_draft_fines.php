<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_inspection_draft_fines', function (Blueprint $table) {
            $table->string('status', 20)->default('Draft')->after('remarks')->index();
            $table->foreignId('guest_fine_id')->nullable()->after('status')
                ->constrained('tbl_guest_fine', 'guest_fine_id')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('guest_fine_id');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_inspection_draft_fines', function (Blueprint $table) {
            $table->dropForeign(['guest_fine_id']);
            $table->dropColumn(['published_at', 'guest_fine_id', 'status']);
        });
    }
};

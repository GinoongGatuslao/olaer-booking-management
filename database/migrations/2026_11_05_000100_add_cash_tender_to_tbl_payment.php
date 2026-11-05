<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash handed over by the guest. The change is calculated (cash paid minus amount
     * paid), so it is not stored. NULL for non-cash payments (for example GCash) and
     * for payments made before this change.
     */
    public function up(): void
    {
        Schema::table('tbl_payment', function (Blueprint $table): void {
            $table->decimal('cash_paid', 10, 2)->nullable()->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_payment', function (Blueprint $table): void {
            $table->dropColumn('cash_paid');
        });
    }
};

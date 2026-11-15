<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GCash refunds are not given by the cashier. After the admin approves the
     * request, the cashier hands it back to the admin (with a note), and the admin
     * sends the GCash money, then records the reference number and proof image
     * (the existing refund_* columns). These columns hold the cashier's hand-off.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tbl_refund_request')) {
            return;
        }

        Schema::table('tbl_refund_request', function (Blueprint $table): void {
            if (! Schema::hasColumn('tbl_refund_request', 'gcash_handoff_amount')) {
                $table->decimal('gcash_handoff_amount', 10, 2)->nullable()->after('refunded_by_user_id');
            }
            if (! Schema::hasColumn('tbl_refund_request', 'gcash_handoff_note')) {
                $table->text('gcash_handoff_note')->nullable()->after('gcash_handoff_amount');
            }
            if (! Schema::hasColumn('tbl_refund_request', 'gcash_handoff_at')) {
                $table->timestamp('gcash_handoff_at')->nullable()->index()->after('gcash_handoff_note');
            }
            if (! Schema::hasColumn('tbl_refund_request', 'gcash_handoff_by_user_id')) {
                $table->unsignedBigInteger('gcash_handoff_by_user_id')->nullable()->index()->after('gcash_handoff_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_refund_request')) {
            return;
        }

        Schema::table('tbl_refund_request', function (Blueprint $table): void {
            foreach (['gcash_handoff_by_user_id', 'gcash_handoff_at', 'gcash_handoff_note', 'gcash_handoff_amount'] as $column) {
                if (Schema::hasColumn('tbl_refund_request', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

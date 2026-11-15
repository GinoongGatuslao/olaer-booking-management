<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The guest's GCash number the cashier gives the admin so the admin knows where to send the refund. */
    public function up(): void
    {
        if (! Schema::hasTable('tbl_refund_request')
            || Schema::hasColumn('tbl_refund_request', 'gcash_handoff_contact_number')) {
            return;
        }

        Schema::table('tbl_refund_request', function (Blueprint $table): void {
            $table->string('gcash_handoff_contact_number', 20)->nullable()->after('gcash_handoff_note');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_refund_request', 'gcash_handoff_contact_number')) {
            Schema::table('tbl_refund_request', function (Blueprint $table): void {
                $table->dropColumn('gcash_handoff_contact_number');
            });
        }
    }
};

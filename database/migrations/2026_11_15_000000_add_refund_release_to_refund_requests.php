<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * After the admin approves a refund, the cashier gives the money back and
     * records how: the mode of payment, the amount actually given, and for
     * GCash the reference number and proof image. A refund counts against
     * revenue on the day it is given (refunded_at).
     */
    public function up(): void
    {
        if (! Schema::hasTable('tbl_refund_request')) {
            return;
        }

        Schema::table('tbl_refund_request', function (Blueprint $table): void {
            if (! Schema::hasColumn('tbl_refund_request', 'refunded_amount')) {
                $table->decimal('refunded_amount', 10, 2)->nullable()->after('approved_amount');
            }
            if (! Schema::hasColumn('tbl_refund_request', 'refund_mode_of_payment_id')) {
                $table->unsignedBigInteger('refund_mode_of_payment_id')->nullable()->index()->after('refunded_amount');
            }
            if (! Schema::hasColumn('tbl_refund_request', 'refund_reference_number')) {
                $table->string('refund_reference_number', 50)->nullable()->after('refund_mode_of_payment_id');
            }
            if (! Schema::hasColumn('tbl_refund_request', 'refund_proof_path')) {
                $table->string('refund_proof_path', 255)->nullable()->after('refund_reference_number');
            }
            if (! Schema::hasColumn('tbl_refund_request', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->index()->after('refund_proof_path');
            }
            if (! Schema::hasColumn('tbl_refund_request', 'refunded_by_user_id')) {
                $table->unsignedBigInteger('refunded_by_user_id')->nullable()->index()->after('refunded_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_refund_request')) {
            return;
        }

        Schema::table('tbl_refund_request', function (Blueprint $table): void {
            foreach (['refunded_by_user_id', 'refunded_at', 'refund_proof_path', 'refund_reference_number', 'refund_mode_of_payment_id', 'refunded_amount'] as $column) {
                if (Schema::hasColumn('tbl_refund_request', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Refund requests for cancelled reservations. A cashier sends a request,
     * the admin approves (and sets the refund amount) or rejects it with a
     * note. Up to 3 requests (attempts) per reservation, one open at a time.
     * Like the other custom tables, no foreign keys.
     */
    public function up(): void
    {
        if (Schema::hasTable('tbl_refund_request')) {
            return;
        }

        Schema::create('tbl_refund_request', function (Blueprint $table): void {
            $table->id('refund_request_id');
            $table->unsignedBigInteger('reservation_id')->index();
            $table->unsignedTinyInteger('attempt_no');
            $table->unsignedBigInteger('requested_by_user_id')->nullable()->index();
            $table->decimal('requested_amount', 10, 2);
            $table->text('request_note')->nullable();
            $table->string('status', 20)->default('Pending')->index();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->decimal('approved_amount', 10, 2)->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->unique(['reservation_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_refund_request');
    }
};

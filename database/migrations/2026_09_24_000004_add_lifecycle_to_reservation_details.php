<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_reservation_details', function (Blueprint $table) {
            $table->string('status', 30)->default('Active')->after('check_out_date')->index();
            $table->string('cancellation_reason', 255)->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at')
                ->constrained('tbl_user', 'user_id')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        DB::table('tbl_reservation')
            ->whereIn('status', ['Cancelled', 'No-show', 'Converted'])
            ->orderBy('reservation_id')
            ->select(['reservation_id', 'status'])
            ->chunkById(100, function ($reservations): void {
                foreach ($reservations as $reservation) {
                    DB::table('tbl_reservation_details')
                        ->where('reservation_id', $reservation->reservation_id)
                        ->update(['status' => $reservation->status]);
                }
            }, 'reservation_id');
    }

    public function down(): void
    {
        Schema::table('tbl_reservation_details', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by_user_id']);
            $table->dropColumn([
                'cancelled_by_user_id',
                'cancelled_at',
                'cancellation_reason',
                'status',
            ]);
        });
    }
};

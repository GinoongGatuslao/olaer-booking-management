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

        DB::table('tbl_reservation_details')
            ->join('tbl_reservation', 'tbl_reservation.reservation_id', '=', 'tbl_reservation_details.reservation_id')
            ->whereIn('tbl_reservation.status', ['Cancelled', 'No-show'])
            ->update([
                'tbl_reservation_details.status' => DB::raw('tbl_reservation.status'),
            ]);

        DB::table('tbl_reservation_details')
            ->join('tbl_reservation', 'tbl_reservation.reservation_id', '=', 'tbl_reservation_details.reservation_id')
            ->where('tbl_reservation.status', 'Converted')
            ->update([
                'tbl_reservation_details.status' => 'Converted',
            ]);
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

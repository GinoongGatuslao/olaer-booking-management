<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_entrance_slip', function (Blueprint $table) {
            $table->foreignId('admitted_by_user_id')
                ->nullable()
                ->after('handled_by_user_id')
                ->constrained('tbl_user', 'user_id')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->timestamp('admitted_at')->nullable()->after('admitted_by_user_id')->index();
        });

        Schema::table('tbl_entrance_slip_details', function (Blueprint $table) {
            $table->decimal('unit_rate_snapshot', 10, 2)->nullable()->after('entrance_fee_id');
            $table->decimal('discount_rate_snapshot', 7, 6)->nullable()->after('discount_id');
            $table->decimal('line_total_snapshot', 10, 2)->nullable()->after('discounted_quantity');
        });

        $this->backfillRateSnapshots();
    }

    public function down(): void
    {
        Schema::table('tbl_entrance_slip_details', function (Blueprint $table) {
            $table->dropColumn(['unit_rate_snapshot', 'discount_rate_snapshot', 'line_total_snapshot']);
        });

        Schema::table('tbl_entrance_slip', function (Blueprint $table) {
            $table->dropForeign(['admitted_by_user_id']);
            $table->dropColumn(['admitted_by_user_id', 'admitted_at']);
        });
    }

    private function backfillRateSnapshots(): void
    {
        DB::table('tbl_entrance_slip_details')
            ->orderBy('entrance_slip_details_id')
            ->chunkById(200, function ($details): void {
                $fees = DB::table('tbl_entrance_fee')
                    ->whereIn('entrance_fee_id', $details->pluck('entrance_fee_id')->unique()->all())
                    ->pluck('entrance_fee_price', 'entrance_fee_id');
                $discounts = DB::table('tbl_discount')
                    ->whereIn('discount_id', $details->pluck('discount_id')->filter()->unique()->all())
                    ->pluck('discount_amount', 'discount_id');

                foreach ($details as $detail) {
                    $unitRate = (float) ($fees[$detail->entrance_fee_id] ?? 0);
                    $discountRate = $detail->discount_id === null
                        ? 0.0
                        : max(0.0, min(1.0, (float) ($discounts[$detail->discount_id] ?? 0)));
                    $quantity = max(0, (int) $detail->guest_quantity);
                    $discountedQuantity = min($quantity, max(0, (int) $detail->discounted_quantity));
                    $discountedUnitRate = round($unitRate * (1 - $discountRate), 2);
                    $lineTotal = round(
                        (($quantity - $discountedQuantity) * $unitRate)
                        + ($discountedQuantity * $discountedUnitRate),
                        2,
                    );

                    DB::table('tbl_entrance_slip_details')
                        ->where('entrance_slip_details_id', $detail->entrance_slip_details_id)
                        ->update([
                            'unit_rate_snapshot' => number_format($unitRate, 2, '.', ''),
                            'discount_rate_snapshot' => number_format($discountRate, 6, '.', ''),
                            'line_total_snapshot' => number_format($lineTotal, 2, '.', ''),
                        ]);
                }
            }, 'entrance_slip_details_id');
    }
};

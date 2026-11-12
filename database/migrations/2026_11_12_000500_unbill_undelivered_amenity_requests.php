<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Amenities are now billed only when a request is Delivered. Requests that
     * are still Pending / Awaiting Payment / Delivering were already added to
     * their booking's total_price and amount_due under the old rule, so take
     * those charges back out. They are added again the moment the request is
     * marked Delivered. Delivered and Cancelled requests are left untouched.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tbl_amenity_request') || ! Schema::hasTable('tbl_booking')) {
            return;
        }

        DB::table('tbl_amenity_request')
            ->whereIn('amenity_request_status', ['Pending', 'Awaiting Payment', 'Delivering'])
            ->orderBy('amenity_request_id')
            ->chunkById(100, function ($requests): void {
                foreach ($requests as $request) {
                    DB::transaction(function () use ($request): void {
                        $booking = DB::table('tbl_booking')
                            ->where('booking_id', $request->booking_id)
                            ->lockForUpdate()
                            ->first();

                        if ($booking === null) {
                            return;
                        }

                        $charge = round((float) $request->total_price, 2);

                        DB::table('tbl_booking')
                            ->where('booking_id', $booking->booking_id)
                            ->update([
                                'total_price' => max(round((float) $booking->total_price - $charge, 2), 0),
                                'amount_due' => max(round((float) $booking->amount_due - $charge, 2), 0),
                            ]);
                    });
                }
            }, 'amenity_request_id');
    }

    public function down(): void
    {
        // Not reversible: re-billing would double-charge requests delivered since.
    }
};

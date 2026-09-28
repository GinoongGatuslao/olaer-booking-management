<?php

use App\Models\BookingDetail;
use App\Models\ReservationDetail;
use App\Services\FacilityScheduleBlockService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $blocks = app(FacilityScheduleBlockService::class);

            ReservationDetail::query()
                ->whereHas('reservation', fn ($query) => $query->whereNotIn('status', ['Cancelled', 'Converted', 'No-show']))
                ->where(fn ($query) => $query->whereNull('status')->orWhereNotIn('status', ['Cancelled', 'Converted', 'No-show']))
                ->chunkById(100, function ($details) use ($blocks): void {
                    foreach ($details as $detail) {
                        $this->ensureCovered($detail, $blocks);
                    }
                }, 'reservation_details_id');

            BookingDetail::query()
                ->whereHas('booking', fn ($query) => $query->whereNotIn('status', ['Cancelled', 'Checked-out', 'Payment Rejected']))
                ->where(fn ($query) => $query->whereNull('status')->orWhereNotIn('status', ['Cancelled', 'Checked-out', 'Payment Rejected']))
                ->chunkById(100, function ($details) use ($blocks): void {
                    foreach ($details as $detail) {
                        $this->ensureCovered($detail, $blocks);
                    }
                }, 'booking_details_id');
        }, attempts: 1);
    }

    public function down(): void
    {
        // Schedule ownership is operational data and must survive a rollback.
    }

    private function ensureCovered(
        ReservationDetail|BookingDetail $detail,
        FacilityScheduleBlockService $blocks,
    ): void {
        $type = $detail instanceof ReservationDetail ? 'reservation' : 'booking';
        $id = $detail->getKey();

        foreach (['facility_id', 'schedule_policy', 'rate_code', 'check_in_date', 'check_out_date'] as $column) {
            if ($detail->getRawOriginal($column) === null || $detail->getRawOriginal($column) === '') {
                throw new RuntimeException(
                    "Active {$type} detail #{$id} has no {$column}; resolve its historical schedule before migration."
                );
            }
        }

        try {
            if ($detail instanceof ReservationDetail) {
                $blocks->acquireForReservationDetail($detail);
            } else {
                $blocks->acquireForBookingDetail($detail);
            }
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException(
                "Active {$type} detail #{$id} has an invalid or conflicting schedule; resolve it before migration: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillSoleDetailGuestCounts(
            'tbl_booking',
            'booking_id',
            'tbl_booking_details',
            'booking_details_id',
        );
        $this->backfillSoleDetailGuestCounts(
            'tbl_reservation',
            'reservation_id',
            'tbl_reservation_details',
            'reservation_details_id',
        );
        $this->correctSoleBookingLineTotalsWithSeparateCharges();

        $ambiguousReservationIds = DB::table('tbl_reservation_details')
            ->select('reservation_id')
            ->groupBy('reservation_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('reservation_id');

        DB::table('tbl_reservation_details')
            ->whereIn('reservation_id', $ambiguousReservationIds)
            ->whereNull('line_total')
            ->where('extra_guest_fee', '0.00')
            ->update(['extra_guest_fee' => null]);

        $multiDetailBookingIds = DB::table('tbl_booking_details')
            ->select('booking_id')
            ->groupBy('booking_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('booking_id');
        $unrecoverableBookingIds = DB::table('tbl_booking_details')
            ->whereIn('booking_id', $multiDetailBookingIds)
            ->where('extra_guest_fee', '0.00')
            ->whereNull('line_total')
            ->distinct()
            ->pluck('booking_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($unrecoverableBookingIds !== []) {
            Log::warning('Batch 2 booking extra-guest zero/null provenance is unrecoverable; values were left unchanged.', [
                'booking_ids' => $unrecoverableBookingIds,
            ]);
        }
    }

    public function down(): void
    {
        // Data provenance cannot be safely reconstructed after this corrective backfill.
    }

    private function backfillSoleDetailGuestCounts(
        string $parentTable,
        string $parentKey,
        string $detailTable,
        string $detailKey,
    ): void {
        $detailCounts = DB::table($detailTable)
            ->select($parentKey, DB::raw('COUNT(*) as detail_count'))
            ->groupBy($parentKey)
            ->pluck('detail_count', $parentKey);

        DB::table($detailTable)
            ->whereNull('guest_count')
            ->orderBy($detailKey)
            ->chunkById(100, function ($details) use ($detailCounts, $parentTable, $parentKey, $detailTable, $detailKey): void {
                foreach ($details as $detail) {
                    if ((int) ($detailCounts[$detail->{$parentKey}] ?? 0) !== 1) {
                        continue;
                    }

                    $guestCount = DB::table($parentTable)
                        ->where($parentKey, $detail->{$parentKey})
                        ->value('total_guest_count');

                    if ($guestCount !== null) {
                        DB::table($detailTable)
                            ->where($detailKey, $detail->{$detailKey})
                            ->update(['guest_count' => $guestCount]);
                    }
                }
            }, $detailKey);
    }

    private function correctSoleBookingLineTotalsWithSeparateCharges(): void
    {
        $detailCounts = DB::table('tbl_booking_details')
            ->select('booking_id', DB::raw('COUNT(*) as detail_count'))
            ->groupBy('booking_id')
            ->pluck('detail_count', 'booking_id');

        DB::table('tbl_booking_details')
            ->join('tbl_booking', 'tbl_booking.booking_id', '=', 'tbl_booking_details.booking_id')
            ->select('tbl_booking_details.*', 'tbl_booking.total_price as parent_total_price')
            ->orderBy('tbl_booking_details.booking_details_id')
            ->chunkById(100, function ($details) use ($detailCounts): void {
                foreach ($details as $detail) {
                    if (
                        (int) ($detailCounts[$detail->booking_id] ?? 0) !== 1
                    ) {
                        continue;
                    }

                    if (
                        $detail->base_price === null
                        || $detail->extra_guest_fee === null
                        || $detail->discount_amount === null
                    ) {
                        continue;
                    }

                    $lineTotal = bcsub(
                        bcadd($this->numeric($detail->base_price), $this->numeric($detail->extra_guest_fee), 2),
                        $this->numeric($detail->discount_amount),
                        2,
                    );
                    $lineTotal = bccomp($lineTotal, '0.00', 2) === -1 ? '0.00' : $lineTotal;
                    $separateCharges = $this->separateChargesTotal((int) $detail->booking_id);

                    if (bccomp($separateCharges, '0.00', 2) !== 1
                        || bccomp(
                            $this->numeric($detail->parent_total_price),
                            bcadd($lineTotal, $separateCharges, 2),
                            2,
                        ) !== 0
                        || ($detail->line_total !== null
                            && bccomp(
                                $this->numeric($detail->line_total),
                                $this->numeric($detail->parent_total_price),
                                2,
                            ) !== 0)
                    ) {
                        continue;
                    }

                    DB::table('tbl_booking_details')
                        ->where('booking_details_id', $detail->booking_details_id)
                        ->update(['line_total' => $lineTotal]);
                }
            }, 'tbl_booking_details.booking_details_id', 'booking_details_id');
    }

    /** @return numeric-string */
    private function separateChargesTotal(int $bookingId): string
    {
        $amounts = DB::table('tbl_amenity_request')
            ->where('booking_id', $bookingId)
            ->where('amenity_request_status', '!=', 'Cancelled')
            ->pluck('total_price')
            ->merge(DB::table('tbl_guest_fine')
                ->where('booking_id', $bookingId)
                ->pluck('total_charge'));

        $total = '0.00';

        foreach ($amounts as $amount) {
            $total = bcadd($total, $this->numeric($amount), 2);
        }

        return $total;
    }

    /** @return numeric-string */
    private function numeric(mixed $amount): string
    {
        $amount = (string) $amount;

        if (! is_numeric($amount)) {
            throw new RuntimeException('Historical monetary values must be numeric.');
        }

        return $amount;
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $bookingDetailCounts = DB::table('tbl_booking_details')
            ->select('booking_id', DB::raw('COUNT(*) as detail_count'))
            ->groupBy('booking_id')
            ->pluck('detail_count', 'booking_id');
        $reservationDetailCounts = DB::table('tbl_reservation_details')
            ->select('reservation_id', DB::raw('COUNT(*) as detail_count'))
            ->groupBy('reservation_id')
            ->pluck('detail_count', 'reservation_id');

        $ambiguousBookingIds = $bookingDetailCounts
            ->filter(fn (mixed $count): bool => (int) $count > 1)
            ->keys()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $ambiguousReservationIds = $reservationDetailCounts
            ->filter(fn (mixed $count): bool => (int) $count > 1)
            ->keys()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($ambiguousBookingIds !== [] || $ambiguousReservationIds !== []) {
            Log::warning(
                'Batch 2 left ambiguous multi-detail guest allocations nullable for manual review.',
                [
                    'booking_ids' => $ambiguousBookingIds,
                    'reservation_ids' => $ambiguousReservationIds,
                ],
            );
        }

        DB::table('tbl_booking_details')
            ->join('tbl_booking', 'tbl_booking.booking_id', '=', 'tbl_booking_details.booking_id')
            ->select([
                'tbl_booking_details.*',
                'tbl_booking.total_guest_count as parent_guest_count',
                'tbl_booking.no_of_extra_guests as parent_extra_guest_count',
                'tbl_booking.total_price as parent_total_price',
            ])
            ->orderBy('tbl_booking_details.booking_details_id')
            ->chunkById(100, function ($details) use ($bookingDetailCounts): void {
                foreach ($details as $detail) {
                    $snapshot = $this->productSnapshot(
                        (int) $detail->facility_id,
                        (string) $detail->rate_type,
                    );

                    if ($snapshot === null) {
                        continue;
                    }

                    $isSingleDetail = (int) ($bookingDetailCounts[$detail->booking_id] ?? 0) === 1;
                    $basePrice = $detail->base_price ?? $snapshot['unit_rate'];
                    $extraGuestFee = $detail->extra_guest_fee
                        ?? ($snapshot['is_room'] && $isSingleDetail
                            ? bcmul($this->numeric($detail->parent_extra_guest_count), '100.00', 2)
                            : '0.00');
                    $lineTotal = $detail->line_total
                        ?? ($isSingleDetail ? $detail->parent_total_price : null);
                    $discountAmount = $detail->discount_amount;

                    if ($discountAmount === null && $lineTotal !== null) {
                        $discountAmount = $this->maxZero(
                            bcsub(
                                bcadd($this->numeric($basePrice), $this->numeric($extraGuestFee), 2),
                                $this->numeric($lineTotal),
                                2,
                            ),
                        );
                    }

                    DB::table('tbl_booking_details')
                        ->where('booking_details_id', $detail->booking_details_id)
                        ->update([
                            ...$snapshot['columns'],
                            'guest_count' => $isSingleDetail
                                ? $detail->parent_guest_count
                                : null,
                            'base_price' => $basePrice,
                            'discount_rate' => $this->discountRate($basePrice, $discountAmount),
                            'discount_amount' => $discountAmount,
                            'extra_guest_fee' => $extraGuestFee,
                            'line_total' => $lineTotal,
                        ]);
                }
            }, 'tbl_booking_details.booking_details_id', 'booking_details_id');

        DB::table('tbl_reservation_details')
            ->join('tbl_reservation', 'tbl_reservation.reservation_id', '=', 'tbl_reservation_details.reservation_id')
            ->select([
                'tbl_reservation_details.*',
                'tbl_reservation.total_guest_count as parent_guest_count',
                'tbl_reservation.no_of_extra_guests as parent_extra_guest_count',
                'tbl_reservation.total_price as parent_total_price',
            ])
            ->orderBy('tbl_reservation_details.reservation_details_id')
            ->chunkById(100, function ($details) use ($reservationDetailCounts): void {
                foreach ($details as $detail) {
                    $snapshot = $this->productSnapshot(
                        (int) $detail->facility_id,
                        (string) $detail->rate_type,
                    );

                    if ($snapshot === null) {
                        continue;
                    }

                    $isSingleDetail = (int) ($reservationDetailCounts[$detail->reservation_id] ?? 0) === 1;
                    $baseUnits = $this->billableUnits(
                        (string) $detail->check_in_date,
                        (string) $detail->check_out_date,
                    );
                    $basePrice = bcmul($snapshot['unit_rate'], $this->numeric($baseUnits), 2);
                    $extraGuestFee = $snapshot['is_room'] && $isSingleDetail
                        ? bcmul($this->numeric($detail->parent_extra_guest_count), '100.00', 2)
                        : '0.00';
                    $lineTotal = $isSingleDetail
                        ? $detail->parent_total_price
                        : null;
                    $discountAmount = $lineTotal !== null
                        ? $this->maxZero(
                            bcsub(
                                bcadd($basePrice, $extraGuestFee, 2),
                                $this->numeric($lineTotal),
                                2,
                            ),
                        )
                        : null;

                    DB::table('tbl_reservation_details')
                        ->where('reservation_details_id', $detail->reservation_details_id)
                        ->update([
                            ...$snapshot['columns'],
                            'guest_count' => $isSingleDetail
                                ? $detail->parent_guest_count
                                : null,
                            'base_price' => $basePrice,
                            'discount_rate' => $this->discountRate($basePrice, $discountAmount),
                            'discount_amount' => $discountAmount,
                            'extra_guest_fee' => $extraGuestFee,
                            'line_total' => $lineTotal,
                        ]);
                }
            }, 'tbl_reservation_details.reservation_details_id', 'reservation_details_id');

        $this->associateBookingExtraGuests();
        $this->associateReservationExtraGuests();
    }

    public function down(): void
    {
        // The preceding schema migration removes only the Batch 2 columns.
    }

    /** @return array{columns: array<string, mixed>, unit_rate: numeric-string, is_room: bool}|null */
    private function productSnapshot(int $facilityId, string $rateType): ?array
    {
        $product = DB::table('tbl_facility')
            ->join(
                'tbl_facility_product',
                'tbl_facility_product.facility_product_id',
                '=',
                'tbl_facility.facility_product_id',
            )
            ->where('tbl_facility.facility_id', $facilityId)
            ->select([
                'tbl_facility_product.facility_product_id',
                'tbl_facility_product.product_code',
                'tbl_facility_product.capacity_policy',
                'tbl_facility_product.included_guest_count',
                'tbl_facility_product.strict_maximum',
                'tbl_facility_product.suggested_minimum',
                'tbl_facility_product.suggested_maximum',
                'tbl_facility_product.schedule_policy',
            ])
            ->first();

        if ($product === null) {
            return null;
        }

        $rateCode = $this->rateCode((string) $product->schedule_policy, $rateType);
        $unitRate = DB::table('tbl_facility_price')
            ->where('facility_id', $facilityId)
            ->where('rate_type', $rateType)
            ->value('facility_price');

        if ($unitRate === null && $rateCode !== null) {
            $unitRate = DB::table('tbl_facility_product_rate')
                ->where('facility_product_id', $product->facility_product_id)
                ->where('rate_code', $rateCode)
                ->value('amount');
        }

        return [
            'columns' => [
                'facility_product_id' => $product->facility_product_id,
                'capacity_policy' => $product->capacity_policy,
                'included_guest_count_snapshot' => $product->included_guest_count,
                'strict_maximum_snapshot' => $product->strict_maximum,
                'suggested_minimum_snapshot' => $product->suggested_minimum,
                'suggested_maximum_snapshot' => $product->suggested_maximum,
                'schedule_policy' => $product->schedule_policy,
                'rate_code' => $rateCode,
                'unit_rate' => $unitRate,
            ],
            'unit_rate' => bcadd($this->numeric($unitRate ?? '0.00'), '0', 2),
            'is_room' => $product->product_code === 'ROOM_STANDARD',
        ];
    }

    private function rateCode(string $schedulePolicy, string $rateType): ?string
    {
        if ($schedulePolicy === 'overnight') {
            return 'OVERNIGHT';
        }

        if ($schedulePolicy === 'whole_calendar_day') {
            return 'WHOLE_DAY';
        }

        $normalized = strtolower(str_replace(['_', '-'], ' ', trim($rateType)));

        if (str_contains($normalized, 'both') || (str_contains($normalized, 'day') && str_contains($normalized, 'night'))) {
            return 'BOTH';
        }

        if (str_starts_with($normalized, 'day')) {
            return 'DAY';
        }

        if (str_starts_with($normalized, 'night')) {
            return 'NIGHT';
        }

        return null;
    }

    private function billableUnits(string $checkInDate, string $checkOutDate): int
    {
        $days = (new DateTimeImmutable($checkInDate))
            ->diff(new DateTimeImmutable($checkOutDate))
            ->days;

        return max((int) $days, 1);
    }

    private function discountRate(mixed $basePrice, mixed $discountAmount): ?string
    {
        if ($basePrice === null || $discountAmount === null) {
            return null;
        }

        if (bccomp($this->numeric($basePrice), '0.00', 2) !== 1) {
            return '0.000000';
        }

        return bcdiv($this->numeric($discountAmount), $this->numeric($basePrice), 6);
    }

    /** @param numeric-string $amount */
    private function maxZero(string $amount): string
    {
        return bccomp($amount, '0.00', 2) === -1
            ? '0.00'
            : bcadd($amount, '0', 2);
    }

    /** @return numeric-string */
    private function numeric(mixed $amount): string
    {
        $numeric = (string) $amount;

        if (! is_numeric($numeric)) {
            throw new RuntimeException('Historical monetary values must be numeric.');
        }

        return $numeric;
    }

    private function associateBookingExtraGuests(): void
    {
        $details = DB::table('tbl_booking_details')
            ->whereNotNull('guest_count')
            ->where('capacity_policy', 'strict')
            ->select('booking_id', 'booking_details_id')
            ->get()
            ->groupBy('booking_id');

        foreach ($details as $bookingId => $bookingDetails) {
            if ($bookingDetails->count() !== 1) {
                continue;
            }

            DB::table('tbl_booking_extra_guests')
                ->where('booking_id', $bookingId)
                ->whereNull('booking_details_id')
                ->update([
                    'booking_details_id' => $bookingDetails->first()->booking_details_id,
                ]);
        }
    }

    private function associateReservationExtraGuests(): void
    {
        $details = DB::table('tbl_reservation_details')
            ->whereNotNull('guest_count')
            ->where('capacity_policy', 'strict')
            ->select('reservation_id', 'reservation_details_id')
            ->get()
            ->groupBy('reservation_id');

        foreach ($details as $reservationId => $reservationDetails) {
            if ($reservationDetails->count() !== 1) {
                continue;
            }

            DB::table('tbl_reservation_extra_guests')
                ->where('reservation_id', $reservationId)
                ->whereNull('reservation_details_id')
                ->update([
                    'reservation_details_id' => $reservationDetails->first()->reservation_details_id,
                ]);
        }
    }
};

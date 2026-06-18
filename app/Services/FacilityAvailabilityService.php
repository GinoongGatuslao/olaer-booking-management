<?php

namespace App\Services;

use App\Models\BookingDetail;
use App\Models\Facility;
use App\Models\ReservationDetail;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class FacilityAvailabilityService
{
    public function isAvailable(
        int $facilityId,
        string $checkInDate,
        string $checkOutDate,
        ?int $excludeReservationId = null,
        ?int $excludeBookingId = null
    ): bool {
        $facility = Facility::query()->find($facilityId);

        if (! $facility || $facility->facility_status !== 'Available') {
            return false;
        }

        [$requestedStart, $requestedEnd] = $this->normalizedPeriod($checkInDate, $checkOutDate);

        $reservationDetails = ReservationDetail::query()
            ->with('reservation')
            ->where('facility_id', $facilityId)
            ->get();

        foreach ($reservationDetails as $detail) {
            if (! $detail->reservation) {
                continue;
            }

            if ($excludeReservationId && (int) $detail->reservation_id === $excludeReservationId) {
                continue;
            }

            if (in_array($detail->reservation->status, ['Cancelled', 'Converted', 'No-show'], true)) {
                continue;
            }

            [$existingStart, $existingEnd] = $this->normalizedPeriod(
                $detail->check_in_date->toDateString(),
                $detail->check_out_date->toDateString(),
            );

            if ($this->periodsOverlap($requestedStart, $requestedEnd, $existingStart, $existingEnd)) {
                return false;
            }
        }

        $bookingDetails = BookingDetail::query()
            ->with('booking')
            ->where('facility_id', $facilityId)
            ->get();

        foreach ($bookingDetails as $detail) {
            if (! $detail->booking) {
                continue;
            }

            if ($excludeBookingId && (int) $detail->booking_id === $excludeBookingId) {
                continue;
            }

            if (in_array($detail->status, ['Cancelled', 'Transferred'], true)) {
                continue;
            }

            if (in_array($detail->booking->status, ['Cancelled'], true)) {
                continue;
            }

            [$existingStart, $existingEnd] = $this->normalizedPeriod(
                $detail->check_in_date->toDateString(),
                $detail->check_out_date->toDateString(),
            );

            if ($this->periodsOverlap($requestedStart, $requestedEnd, $existingStart, $existingEnd)) {
                return false;
            }
        }

        return true;
    }

    /**
     * For same-day cottage/function-hall use, the database stores the same check-in and check-out date.
     * Internally, treat that as one full calendar-day block to avoid double-booking the same facility.
     */
    private function normalizedPeriod(string $checkInDate, string $checkOutDate): array
    {
        $start = CarbonImmutable::parse($checkInDate)->startOfDay();
        $end = CarbonImmutable::parse($checkOutDate)->startOfDay();

        if ($end->lessThan($start)) {
            throw new InvalidArgumentException('Check-out date cannot be before check-in date.');
        }

        if ($end->equalTo($start)) {
            $end = $start->addDay();
        }

        return [$start, $end];
    }

    private function periodsOverlap(
        CarbonImmutable $requestedStart,
        CarbonImmutable $requestedEnd,
        CarbonImmutable $existingStart,
        CarbonImmutable $existingEnd
    ): bool {
        return $requestedStart->lessThan($existingEnd) && $requestedEnd->greaterThan($existingStart);
    }
}

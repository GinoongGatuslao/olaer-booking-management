<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class BookingAvailabilityService
{
    public function isFacilityAvailable(
        int $facilityId,
        string $checkInDate,
        string $checkOutDate,
        ?int $ignoreBookingDetailsId = null,
        ?int $ignoreReservationDetailsId = null
    ): bool {
        if ($checkOutDate <= $checkInDate) {
            return false;
        }

        if ($this->hasBookingConflict($facilityId, $checkInDate, $checkOutDate, $ignoreBookingDetailsId)) {
            return false;
        }

        if ($this->hasReservationConflict($facilityId, $checkInDate, $checkOutDate, $ignoreReservationDetailsId)) {
            return false;
        }

        return true;
    }

    public function assertFacilityAvailable(
        int $facilityId,
        string $checkInDate,
        string $checkOutDate,
        ?int $ignoreBookingDetailsId = null,
        ?int $ignoreReservationDetailsId = null
    ): void {
        if (! $this->isFacilityAvailable($facilityId, $checkInDate, $checkOutDate, $ignoreBookingDetailsId, $ignoreReservationDetailsId)) {
            throw new InvalidArgumentException('Selected facility is not available for the selected date range.');
        }
    }

    private function hasBookingConflict(int $facilityId, string $checkInDate, string $checkOutDate, ?int $ignoreBookingDetailsId): bool
    {
        if (! Schema::hasTable('tbl_booking_details')) {
            return false;
        }

        $query = DB::table('tbl_booking_details')
            ->where('facility_id', $facilityId)
            ->where('check_in_date', '<', $checkOutDate)
            ->where('check_out_date', '>', $checkInDate);

        if ($ignoreBookingDetailsId !== null) {
            $query->where('booking_details_id', '!=', $ignoreBookingDetailsId);
        }

        if (Schema::hasColumn('tbl_booking_details', 'status')) {
            $query->whereNotIn('status', [
                'Cancelled',
                'Checked-out',
                'Transferred',
                'Payment Rejected',
                'Rejected',
            ]);
        }

        return $query->exists();
    }

    private function hasReservationConflict(int $facilityId, string $checkInDate, string $checkOutDate, ?int $ignoreReservationDetailsId): bool
    {
        if (! Schema::hasTable('tbl_reservation_details')) {
            return false;
        }

        $query = DB::table('tbl_reservation_details')
            ->where('facility_id', $facilityId)
            ->where('check_in_date', '<', $checkOutDate)
            ->where('check_out_date', '>', $checkInDate);

        if ($ignoreReservationDetailsId !== null) {
            $query->where('reservation_details_id', '!=', $ignoreReservationDetailsId);
        }

        if (Schema::hasTable('tbl_reservation') && Schema::hasColumn('tbl_reservation', 'status')) {
            $query->join('tbl_reservation', 'tbl_reservation.reservation_id', '=', 'tbl_reservation_details.reservation_id')
                ->whereNotIn('tbl_reservation.status', ['Cancelled', 'Converted', 'Payment Rejected', 'Rejected']);
        }

        return $query->exists();
    }
}

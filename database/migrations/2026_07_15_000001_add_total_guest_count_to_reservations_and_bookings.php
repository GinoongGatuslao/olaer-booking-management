<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_reservation', function (Blueprint $table): void {
            $table
                ->unsignedInteger('total_guest_count')
                ->nullable()
                ->after('no_of_extra_guests');
        });

        Schema::table('tbl_booking', function (Blueprint $table): void {
            $table
                ->unsignedInteger('total_guest_count')
                ->nullable()
                ->after('no_of_extra_guests');
        });

        $this->backfillReservations();
        $this->backfillBookings();
    }

    public function down(): void
    {
        Schema::table('tbl_booking', function (Blueprint $table): void {
            $table->dropColumn('total_guest_count');
        });

        Schema::table('tbl_reservation', function (Blueprint $table): void {
            $table->dropColumn('total_guest_count');
        });
    }

    private function backfillReservations(): void
    {
        $rows = DB::table('tbl_reservation as reservation')
            ->leftJoin(
                'tbl_reservation_details as detail',
                'detail.reservation_id',
                '=',
                'reservation.reservation_id',
            )
            ->leftJoin(
                'tbl_facility as facility',
                'facility.facility_id',
                '=',
                'detail.facility_id',
            )
            ->leftJoin(
                'tbl_facility_type as facility_type',
                'facility_type.facility_type_id',
                '=',
                'facility.facility_type_id',
            )
            ->select([
                'reservation.reservation_id',
                'reservation.no_of_extra_guests',
                'facility_type.facility_type',
            ])
            ->get();

        foreach ($rows as $row) {
            DB::table('tbl_reservation')
                ->where(
                    'reservation_id',
                    $row->reservation_id,
                )
                ->update([
                    'total_guest_count' =>
                        $this->inferLegacyTotal(
                            $row->facility_type,
                            (int) ($row->no_of_extra_guests ?? 0),
                        ),
                ]);
        }
    }

    private function backfillBookings(): void
    {
        $rows = DB::table('tbl_booking as booking')
            ->leftJoin(
                'tbl_booking_details as detail',
                'detail.booking_id',
                '=',
                'booking.booking_id',
            )
            ->leftJoin(
                'tbl_facility as facility',
                'facility.facility_id',
                '=',
                'detail.facility_id',
            )
            ->leftJoin(
                'tbl_facility_type as facility_type',
                'facility_type.facility_type_id',
                '=',
                'facility.facility_type_id',
            )
            ->select([
                'booking.booking_id',
                'booking.no_of_extra_guests',
                'facility_type.facility_type',
            ])
            ->get();

        foreach ($rows as $row) {
            DB::table('tbl_booking')
                ->where('booking_id', $row->booking_id)
                ->update([
                    'total_guest_count' =>
                        $this->inferLegacyTotal(
                            $row->facility_type,
                            (int) ($row->no_of_extra_guests ?? 0),
                        ),
                ]);
        }
    }

    private function inferLegacyTotal(
        ?string $facilityType,
        int $storedExtraGuestCount,
    ): int {
        if (strtolower((string) $facilityType) === 'room') {
            return max(1, 4 + $storedExtraGuestCount);
        }

        return max(1, 1 + $storedExtraGuestCount);
    }
};

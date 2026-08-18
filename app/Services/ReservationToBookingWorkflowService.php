<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\FacilityType;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\ReservationExtraGuest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReservationToBookingWorkflowService
{
    public function __construct(
        private readonly FacilityOccupancyService $occupancy,
        private readonly FacilityScheduleLockService $scheduleLock,
        private readonly BookingAvailabilityService $availability,
        private readonly GcashReferenceIntegrityService $gcashReferences,
        private readonly DetailExtraGuestService $detailGuests,
        private readonly DecimalMoneyService $money,
    ) {}

    public function convert(
        int $reservationId,
        array $data,
    ): Booking {
        return DB::transaction(function () use (
            $reservationId,
            $data,
        ): Booking {
            $userId = (int) ($data['user_id'] ?? 0);
            $this->guardCashier($userId);

            $reservation = Reservation::query()
                ->with([
                    'guest',
                    'payments',
                ])
                ->lockForUpdate()
                ->findOrFail($reservationId);

            $reservationDetails = ReservationDetail::query()
                ->with(['facility.facilityType', 'facilityProduct', 'discount'])
                ->where('reservation_id', $reservation->reservation_id)
                ->lockForUpdate()
                ->get();
            $reservationExtraGuests = ReservationExtraGuest::query()
                ->where('reservation_id', $reservation->reservation_id)
                ->lockForUpdate()
                ->get();

            $this->guardConvertible($reservation, $reservationDetails);

            $this->scheduleLock->lockMany(
                $reservationDetails
                    ->pluck('facility_id')
                    ->map(fn ($facilityId): int => (int) $facilityId)
                    ->all(),
            );

            foreach ($reservationDetails as $detail) {
                $this->availability->assertFacilityAvailable(
                    (int) $detail->facility_id,
                    Carbon::parse($detail->check_in_date)->toDateString(),
                    Carbon::parse($detail->check_out_date)->toDateString(),
                    null,
                    (int) $detail->reservation_details_id,
                );
            }

            $amountDue = $this->money->normalize($reservation->amount_due);

            $paymentAmount = $this->money->normalize(
                (string) ($data['payment_amount'] ?? '0.00'),
            );

            $modeOfPaymentId =
                $data['mode_of_payment_id'] ?? null;

            $referenceNumber = trim(
                (string) ($data['reference_number'] ?? ''),
            );

            if (
                bccomp($amountDue, '0.00', 2) === 1
                && ! $this->money->equals($paymentAmount, $amountDue)
            ) {
                throw new InvalidArgumentException(
                    'Reservation conversion requires exact full payment of the remaining balance.',
                );
            }

            if (bccomp($amountDue, '0.00', 2) !== 1) {
                $paymentAmount = '0.00';
            }

            $mode = null;

            if (bccomp($paymentAmount, '0.00', 2) === 1) {
                if (! $modeOfPaymentId) {
                    throw new InvalidArgumentException(
                        'Mode of payment is required.',
                    );
                }

                $mode = ModeOfPayment::query()
                    ->findOrFail((int) $modeOfPaymentId);

                if (
                    strtolower(trim((string) $mode->mode_of_payment))
                    === 'gcash'
                ) {
                    $referenceNumber = $this->gcashReferences
                        ->assertAvailable($referenceNumber);
                }
            }

            $firstDetail = $reservationDetails->first();

            $totalGuestCount = (int) (
                $reservation->total_guest_count
                ?: (
                    $firstDetail?->facility
                        ? $this->occupancy->legacyTotalGuestCount(
                            $firstDetail->facility,
                            (int) $reservation->no_of_extra_guests,
                        )
                        : max(
                            1,
                            (int) $reservation->no_of_extra_guests + 1,
                        )
                )
            );

            $booking = Booking::query()->create([
                'b_ref_no' => $this->newReference('B'),
                'guest_id' => $reservation->guest_id,
                'booking_date' => Carbon::today()->toDateString(),
                'no_of_extra_guests' => (int) $reservation->no_of_extra_guests,
                'total_guest_count' => $totalGuestCount,
                'total_price' => $reservation->total_price,
                'amount_due' => 0.00,
                'user_id' => $userId,
                'reservation_id' => $reservation->reservation_id,
                'entrance_slip_id' => null,
                'status' => 'Booked',
            ]);

            $bookingDetailsByReservationDetailId = [];

            foreach ($reservationDetails as $detail) {
                $bookingDetail = BookingDetail::query()->create([
                    'booking_id' => $booking->booking_id,
                    'facility_id' => $detail->facility_id,
                    'rate_type' => $detail->rate_type,
                    'check_in_date' => Carbon::parse($detail->check_in_date)->toDateString(),
                    'check_out_date' => Carbon::parse($detail->check_out_date)->toDateString(),
                    'check_in_time' => $this->defaultCheckInTime($detail),
                    'status' => 'Booked',
                    'discount_id' => $detail->discount_id,
                    'user_id' => $userId,
                    ...$this->snapshotValues($detail),
                ]);

                $bookingDetailsByReservationDetailId[
                    (int) $detail->reservation_details_id
                ] = $bookingDetail;
            }

            $extraGuestsByReservationDetailId = [];

            foreach ($reservationExtraGuests as $extraGuest) {
                $reservationDetailsId = $extraGuest->reservation_details_id;

                if (
                    $reservationDetailsId === null
                    && count($bookingDetailsByReservationDetailId) === 1
                ) {
                    $reservationDetailsId = array_key_first(
                        $bookingDetailsByReservationDetailId,
                    );
                }

                $bookingDetail = $bookingDetailsByReservationDetailId[
                    (int) $reservationDetailsId
                ] ?? null;

                if (! $bookingDetail instanceof BookingDetail) {
                    throw new InvalidArgumentException(
                        'A reservation extra guest is not assigned to a valid room detail.',
                    );
                }

                $extraGuestsByReservationDetailId[(int) $reservationDetailsId][] = [
                    'first_name' => $extraGuest->first_name,
                    'middle_name' => $extraGuest->middle_name,
                    'last_name' => $extraGuest->last_name,
                ];
            }

            foreach ($bookingDetailsByReservationDetailId as $reservationDetailsId => $bookingDetail) {
                $this->detailGuests->createForBooking(
                    $booking,
                    $bookingDetail,
                    $extraGuestsByReservationDetailId[$reservationDetailsId] ?? [],
                );
            }

            if (bccomp($paymentAmount, '0.00', 2) === 1) {
                Payment::query()->create([
                    'p_ref_no' => $this->newReference('P'),
                    'booking_id' => $booking->booking_id,
                    'reservation_id' => $reservation->reservation_id,
                    'entrance_slip_id' => null,
                    'mode_of_payment_id' => $mode->mode_of_payment_id,
                    'reference_number' => $referenceNumber !== ''
                            ? $referenceNumber
                            : null,
                    'proof_of_payment_path' => null,
                    'amount_paid' => $paymentAmount,
                    'date_paid' => Carbon::today()->toDateString(),
                    'user_id' => $userId,
                    'payment_status' => 'Verified',
                    'verified_by_user_id' => $userId,
                    'verified_at' => Carbon::now(),
                ]);
            }

            $reservation->update([
                'status' => 'Converted',
                'amount_due' => 0.00,
            ]);

            return $booking->fresh([
                'guest',
                'reservation',
                'details.facility.facilityType',
                'details.discount',
                'extraGuests',
                'payments',
            ]);
        });
    }

    private function guardCashier(int $userId): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException(
                'A logged-in cashier is required to convert reservations.',
            );
        }

        $user = User::query()
            ->with('role')
            ->findOrFail($userId);

        if ($user->role?->role_name !== 'Cashier') {
            throw new InvalidArgumentException(
                'Only a Cashier may convert reservations to bookings.',
            );
        }
    }

    /** @param Collection<int, ReservationDetail> $details */
    private function guardConvertible(
        Reservation $reservation,
        Collection $details,
    ): void {
        if (
            ! in_array(
                (string) $reservation->status,
                ['Active', 'Paid'],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                'Only active or fully paid reservations can be converted to bookings.',
            );
        }

        if (
            (string) $reservation->status === 'Paid'
            && bccomp((string) $reservation->amount_due, '0.00', 2) === 1
        ) {
            throw new InvalidArgumentException(
                'This paid reservation has an inconsistent remaining balance and must be reviewed before conversion.',
            );
        }

        if ($reservation->booking()->exists()) {
            throw new InvalidArgumentException(
                'This reservation already has a booking.',
            );
        }

        if ($details->isEmpty()) {
            throw new InvalidArgumentException(
                'Reservation has no facility details.',
            );
        }

        foreach ($details as $detail) {
            if ((int) $detail->reservation_id !== (int) $reservation->reservation_id) {
                throw new InvalidArgumentException(
                    'Reservation contains a facility detail owned by another transaction.',
                );
            }

            if (
                $detail->facility === null
            ) {
                throw new InvalidArgumentException(
                    'Reservation has a missing facility assignment.',
                );
            }

            if (
                Carbon::parse($detail->check_out_date)
                    ->lt(Carbon::parse($detail->check_in_date))
            ) {
                throw new InvalidArgumentException(
                    'Reservation contains an invalid facility date range.',
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function snapshotValues(ReservationDetail $detail): array
    {
        return [
            'facility_product_id' => $detail->facility_product_id,
            'guest_count' => $detail->guest_count,
            'capacity_policy' => $detail->getRawOriginal('capacity_policy'),
            'included_guest_count_snapshot' => $detail->included_guest_count_snapshot,
            'strict_maximum_snapshot' => $detail->strict_maximum_snapshot,
            'suggested_minimum_snapshot' => $detail->suggested_minimum_snapshot,
            'suggested_maximum_snapshot' => $detail->suggested_maximum_snapshot,
            'schedule_policy' => $detail->getRawOriginal('schedule_policy'),
            'rate_code' => $detail->getRawOriginal('rate_code'),
            'unit_rate' => $detail->unit_rate,
            'base_price' => $detail->base_price,
            'discount_rate' => $detail->discount_rate,
            'discount_amount' => $detail->discount_amount,
            'extra_guest_fee' => $detail->extra_guest_fee,
            'line_total' => $detail->line_total,
        ];
    }

    private function defaultCheckInTime(
        ReservationDetail $detail,
    ): string {
        $facilityType = strtolower((string) FacilityType::query()
            ->whereKey($detail->facility?->facility_type_id)
            ->value('facility_type'));

        return match ($facilityType) {
            'cottage' => '06:00:00',
            'function hall' => '06:00:00',
            default => '12:00:00',
        };
    }

    private function newReference(string $prefix): string
    {
        do {
            $reference = $prefix
                .now()->format('ymdHis')
                .strtoupper(Str::random(4));

            $exists = $prefix === 'B'
                ? Booking::query()
                    ->where('b_ref_no', $reference)
                    ->exists()
                : Payment::query()
                    ->where('p_ref_no', $reference)
                    ->exists();
        } while ($exists);

        return $reference;
    }
}

<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\BookingExtraGuest;
use App\Models\FacilityPrice;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ReservationToBookingWorkflowService
{
    public function __construct(
        private readonly ReservationQuoteService $quoteService,
        private readonly FacilityOccupancyService $occupancy,
        private readonly FacilityScheduleLockService $scheduleLock,
        private readonly BookingAvailabilityService $availability,
        private readonly GcashReferenceIntegrityService $gcashReferences,
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
                    'details.facility.facilityType',
                    'details.discount',
                    'extraGuests',
                    'payments',
                ])
                ->lockForUpdate()
                ->findOrFail($reservationId);

            $this->guardConvertible($reservation);

            $this->scheduleLock->lockMany(
                $reservation->details
                    ->pluck('facility_id')
                    ->map(fn ($facilityId): int => (int) $facilityId)
                    ->all(),
            );

            foreach ($reservation->details as $detail) {
                $this->availability->assertFacilityAvailable(
                    (int) $detail->facility_id,
                    $detail->check_in_date->toDateString(),
                    $detail->check_out_date->toDateString(),
                    null,
                    (int) $detail->reservation_details_id,
                );
            }

            $amountDue = round(
                (float) $reservation->amount_due,
                2,
            );

            $paymentAmount = round(
                (float) ($data['payment_amount'] ?? 0),
                2,
            );

            $modeOfPaymentId =
                $data['mode_of_payment_id'] ?? null;

            $referenceNumber = trim(
                (string) ($data['reference_number'] ?? ''),
            );

            if (
                $amountDue > 0
                && abs($paymentAmount - $amountDue) > 0.009
            ) {
                throw new InvalidArgumentException(
                    'Reservation conversion requires exact full payment of the remaining balance.',
                );
            }

            if ($amountDue <= 0) {
                $paymentAmount = 0.00;
            }

            $mode = null;

            if ($paymentAmount > 0) {
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

            $firstDetail = $reservation->details->first();

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
                'no_of_extra_guests' =>
                    (int) $reservation->no_of_extra_guests,
                'total_guest_count' => $totalGuestCount,
                'total_price' => $reservation->total_price,
                'amount_due' => 0.00,
                'user_id' => $userId,
                'reservation_id' => $reservation->reservation_id,
                'entrance_slip_id' => null,
                'status' => 'Booked',
            ]);

            foreach ($reservation->details as $detail) {
                $quote = $this->quoteForReservationDetail(
                    $detail,
                    $totalGuestCount,
                );

                BookingDetail::query()->create([
                    'booking_id' => $booking->booking_id,
                    'facility_id' => $detail->facility_id,
                    'rate_type' => $detail->rate_type,
                    'check_in_date' =>
                        $detail->check_in_date->toDateString(),
                    'check_out_date' =>
                        $detail->check_out_date->toDateString(),
                    'check_in_time' =>
                        $this->defaultCheckInTime($detail),
                    'status' => 'Booked',
                    'discount_id' => $detail->discount_id,
                    'user_id' => $userId,
                    'base_price' =>
                        $quote['base_price'] ?? null,
                    'discount_amount' =>
                        $quote['discount_amount'] ?? null,
                    'extra_guest_fee' =>
                        $quote['extra_guest_charge'] ?? null,
                    'line_total' =>
                        $quote['total_price'] ?? null,
                ]);
            }

            foreach ($reservation->extraGuests as $extraGuest) {
                BookingExtraGuest::query()->create([
                    'booking_id' => $booking->booking_id,
                    'first_name' => $extraGuest->first_name,
                    'middle_name' => $extraGuest->middle_name,
                    'last_name' => $extraGuest->last_name,
                ]);
            }

            if ($paymentAmount > 0 && $mode !== null) {
                Payment::query()->create([
                    'p_ref_no' => $this->newReference('P'),
                    'booking_id' => $booking->booking_id,
                    'reservation_id' =>
                        $reservation->reservation_id,
                    'entrance_slip_id' => null,
                    'mode_of_payment_id' =>
                        $mode->mode_of_payment_id,
                    'reference_number' =>
                        $referenceNumber !== ''
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

    private function guardConvertible(
        Reservation $reservation,
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
            && round((float) $reservation->amount_due, 2) > 0.009
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

        if ($reservation->details->isEmpty()) {
            throw new InvalidArgumentException(
                'Reservation has no facility details.',
            );
        }

        foreach ($reservation->details as $detail) {
            if (
                $detail->facility_id === null
                || $detail->facility === null
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

    private function quoteForReservationDetail(
        ReservationDetail $detail,
        int $totalGuestCount,
    ): array {
        try {
            return $this->quoteService->quote(
                facilityId: (int) $detail->facility_id,
                rateType: (string) $detail->rate_type,
                checkInDate:
                    $detail->check_in_date->toDateString(),
                checkOutDate:
                    $detail->check_out_date->toDateString(),
                discountId:
                    $detail->discount_id
                        ? (int) $detail->discount_id
                        : null,
                totalGuestCount: $totalGuestCount,
            );
        } catch (\Throwable) {
            $price = FacilityPrice::query()
                ->where('facility_id', $detail->facility_id)
                ->where('rate_type', $detail->rate_type)
                ->value('facility_price');

            return [
                'base_price' =>
                    $price !== null ? (float) $price : null,
                'discount_amount' => null,
                'extra_guest_charge' => null,
                'total_price' => null,
            ];
        }
    }

    private function defaultCheckInTime(
        ReservationDetail $detail,
    ): string {
        $facilityType = strtolower(
            (string) $detail
                ->facility
                ?->facilityType
                ?->facility_type,
        );

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

<?php

namespace App\Services;

use App\FacilityProductCode;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\BookingExtraGuest;
use App\Models\Facility;
use App\Models\FacilityProduct;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\ReservationExtraGuest;
use InvalidArgumentException;

class DetailExtraGuestService
{
    /** @param array<int, array<string, mixed>> $extraGuests */
    public function createForBooking(
        Booking $booking,
        BookingDetail $detail,
        array $extraGuests,
    ): void {
        if ((int) $detail->booking_id !== (int) $booking->booking_id) {
            throw new InvalidArgumentException(
                'Extra guests must belong to a room detail in the same booking.',
            );
        }

        if ($extraGuests === []) {
            $this->assertExpectedCount($detail, $extraGuests);

            return;
        }

        $this->assertRoomDetail($detail);
        $this->assertExpectedCount($detail, $extraGuests);

        foreach ($extraGuests as $extraGuest) {
            $names = $this->validatedNames($extraGuest);

            BookingExtraGuest::query()->create([
                'booking_id' => $booking->booking_id,
                'booking_details_id' => $detail->booking_details_id,
                ...$names,
            ]);
        }
    }

    /** @param array<int, array<string, mixed>> $extraGuests */
    public function createForReservation(
        Reservation $reservation,
        ReservationDetail $detail,
        array $extraGuests,
    ): void {
        if ((int) $detail->reservation_id !== (int) $reservation->reservation_id) {
            throw new InvalidArgumentException(
                'Extra guests must belong to a room detail in the same reservation.',
            );
        }

        if ($extraGuests === []) {
            $this->assertExpectedCount($detail, $extraGuests);

            return;
        }

        $this->assertRoomDetail($detail);
        $this->assertExpectedCount($detail, $extraGuests);

        foreach ($extraGuests as $extraGuest) {
            $names = $this->validatedNames($extraGuest);

            ReservationExtraGuest::query()->create([
                'reservation_id' => $reservation->reservation_id,
                'reservation_details_id' => $detail->reservation_details_id,
                ...$names,
            ]);
        }
    }

    private function assertRoomDetail(
        BookingDetail|ReservationDetail $detail,
    ): void {
        $productId = $detail->facility_product_id
            ?? Facility::query()
                ->whereKey($detail->facility_id)
                ->value('facility_product_id');
        $product = FacilityProduct::query()->find($productId);

        if (
            ! $product instanceof FacilityProduct
            || $product->product_code !== FacilityProductCode::RoomStandard
        ) {
            throw new InvalidArgumentException(
                'Extra guests can only be assigned to a room detail.',
            );
        }
    }

    /** @param array<int, array<string, mixed>> $extraGuests */
    private function assertExpectedCount(
        BookingDetail|ReservationDetail $detail,
        array $extraGuests,
    ): void {
        if (
            $detail->guest_count === null
            || $detail->included_guest_count_snapshot === null
        ) {
            return;
        }

        $expected = max(
            0,
            (int) $detail->guest_count
                - (int) $detail->included_guest_count_snapshot,
        );

        if (count($extraGuests) !== $expected) {
            throw new InvalidArgumentException(
                "The room detail requires exactly {$expected} paid extra guest name(s).",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $extraGuest
     * @return array{first_name: string, middle_name: ?string, last_name: string}
     */
    private function validatedNames(array $extraGuest): array
    {
        $firstName = trim((string) ($extraGuest['first_name'] ?? ''));
        $middleName = trim((string) ($extraGuest['middle_name'] ?? ''));
        $lastName = trim((string) ($extraGuest['last_name'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            throw new InvalidArgumentException(
                'Each extra guest must have a first name and last name.',
            );
        }

        return [
            'first_name' => $firstName,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'last_name' => $lastName,
        ];
    }
}

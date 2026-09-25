<?php

namespace App\Services;

use App\Models\BookingDetail;
use App\Models\ReservationDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RoomOccupantService
{
    public function __construct(
        private readonly DetailExtraGuestService $detailGuests,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $occupants
     * @return array<int, array{first_name: string, middle_name: ?string, last_name: string}>
     */
    public function normalize(array $occupants, int $expectedCount): array
    {
        if (count($occupants) !== $expectedCount) {
            throw new InvalidArgumentException(
                "This room requires exactly {$expectedCount} named occupant(s).",
            );
        }

        return collect($occupants)->values()->map(function (array $occupant): array {
            $firstName = trim((string) ($occupant['first_name'] ?? ''));
            $middleName = trim((string) ($occupant['middle_name'] ?? ''));
            $lastName = trim((string) ($occupant['last_name'] ?? ''));

            if ($firstName === '' || $lastName === '') {
                throw new InvalidArgumentException(
                    'Every room occupant must have a first name and last name.',
                );
            }

            return [
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
            ];
        })->all();
    }

    /** @param array<int, array<string, mixed>> $occupants */
    public function createForReservation(
        ReservationDetail $detail,
        array $occupants,
        int $expectedCount,
    ): array {
        $normalized = $this->normalize($occupants, $expectedCount);

        foreach ($normalized as $index => $occupant) {
            $detail->roomOccupants()->create([
                ...$occupant,
                'position' => $index + 1,
            ]);
        }

        return $normalized;
    }

    /** @param array<int, array<string, mixed>> $occupants */
    public function createForBooking(
        BookingDetail $detail,
        array $occupants,
        int $expectedCount,
    ): array {
        $normalized = $this->normalize($occupants, $expectedCount);

        foreach ($normalized as $index => $occupant) {
            $detail->roomOccupants()->create([
                ...$occupant,
                'position' => $index + 1,
            ]);
        }

        return $normalized;
    }

    /**
     * Staff-only replacement of all names for one reserved room.
     *
     * @param array<int, array<string, mixed>> $occupants
     */
    public function replaceReservationOccupants(
        int $reservationDetailId,
        array $occupants,
        int $userId,
    ): ReservationDetail {
        $this->guardAuthorizedStaff($userId);

        return DB::transaction(function () use ($reservationDetailId, $occupants): ReservationDetail {
            $detail = ReservationDetail::query()
                ->with(['reservation', 'roomOccupants', 'extraGuests'])
                ->lockForUpdate()
                ->findOrFail($reservationDetailId);

            if ($detail->included_guest_count_snapshot === null || $detail->strict_maximum_snapshot === null) {
                throw new InvalidArgumentException('Only room details have editable occupant lists.');
            }

            $normalized = $this->normalize($occupants, (int) $detail->guest_count);

            $detail->roomOccupants()->delete();
            foreach ($normalized as $index => $occupant) {
                $detail->roomOccupants()->create([
                    ...$occupant,
                    'position' => $index + 1,
                ]);
            }

            $detail->extraGuests()->delete();
            $this->detailGuests->createForReservation(
                $detail->reservation,
                $detail,
                array_slice($normalized, (int) $detail->included_guest_count_snapshot),
            );

            return $detail->fresh(['roomOccupants', 'extraGuests']);
        }, attempts: 3);
    }

    /**
     * Staff-only replacement of all names for one booked room.
     *
     * @param array<int, array<string, mixed>> $occupants
     */
    public function replaceBookingOccupants(
        int $bookingDetailId,
        array $occupants,
        int $userId,
    ): BookingDetail {
        $this->guardAuthorizedStaff($userId);

        return DB::transaction(function () use ($bookingDetailId, $occupants): BookingDetail {
            $detail = BookingDetail::query()
                ->with(['booking', 'roomOccupants', 'extraGuests'])
                ->lockForUpdate()
                ->findOrFail($bookingDetailId);

            if ($detail->included_guest_count_snapshot === null || $detail->strict_maximum_snapshot === null) {
                throw new InvalidArgumentException('Only room details have editable occupant lists.');
            }

            $normalized = $this->normalize($occupants, (int) $detail->guest_count);

            $detail->roomOccupants()->delete();
            foreach ($normalized as $index => $occupant) {
                $detail->roomOccupants()->create([
                    ...$occupant,
                    'position' => $index + 1,
                ]);
            }

            $detail->extraGuests()->delete();
            $this->detailGuests->createForBooking(
                $detail->booking,
                $detail,
                array_slice($normalized, (int) $detail->included_guest_count_snapshot),
            );

            return $detail->fresh(['roomOccupants', 'extraGuests']);
        }, attempts: 3);
    }

    private function guardAuthorizedStaff(int $userId): void
    {
        $user = User::query()->with('role')->findOrFail($userId);
        $role = (string) $user->role?->role_name;

        if (! in_array($role, ['Admin', 'Cashier'], true)) {
            throw new InvalidArgumentException(
                'Only authorized administrative or cashier staff may edit room occupants.',
            );
        }
    }
}

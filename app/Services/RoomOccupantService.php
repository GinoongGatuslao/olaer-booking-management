<?php

namespace App\Services;

use App\Models\BookingDetail;
use App\Models\ReservationDetail;
use InvalidArgumentException;

class RoomOccupantService
{
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
}

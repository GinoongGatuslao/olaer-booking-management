<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\ReservationExtraGuest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PublicReservationWorkflowService
{
    public function __construct(
        private readonly FacilityAvailabilityService $availability,
        private readonly ReservationQuoteService $quoteService,
        private readonly DiscountResolverService $discountResolver,
        private readonly GuestConfirmationEmailService $confirmationEmailService,
    ) {}

    public function createGuestReservation(array $data): Reservation
    {
        $reservation = DB::transaction(function () use ($data): Reservation {
            $facilityId = (int) $data['facility_id'];
            $rateType = (string) $data['rate_type'];
            $checkInDate = (string) $data['check_in_date'];
            $checkOutDate = (string) $data['check_out_date'];
            $preferredDiscountId = filled($data['discount_id'] ?? null) ? (int) $data['discount_id'] : null;
            $resolvedDiscount = $this->discountResolver->resolveForFacility($facilityId, $checkInDate, $preferredDiscountId);
            $discountId = $resolvedDiscount?->discount_id;
            $extraGuests = $this->cleanExtraGuests($data['extra_guests'] ?? []);
            $extraGuestCount = count($extraGuests);

            if (! $this->availability->isAvailable($facilityId, $checkInDate, $checkOutDate)) {
                throw new InvalidArgumentException('Selected facility is no longer available for the selected date range.');
            }

            $quote = $this->quoteService->quote(
                facilityId: $facilityId,
                rateType: $rateType,
                checkInDate: $checkInDate,
                checkOutDate: $checkOutDate,
                discountId: $discountId,
                extraGuestCount: $extraGuestCount,
            );

            $address = Address::query()->create([
                'purok' => $data['purok'] ?? null,
                'province' => $data['province'],
                'city' => $data['city'],
                'barangay' => $data['barangay'] ?? null,
            ]);

            $guest = Guest::query()->create([
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'contact_no' => $data['contact_no'],
                'email' => $data['email'],
                'address_id' => $address->address_id,
            ]);

            $reservation = Reservation::query()->create([
                'r_ref_no' => $this->newReservationReference(),
                'guest_id' => $guest->guest_id,
                'reservation_date' => Carbon::today()->toDateString(),
                'total_price' => $quote['total_price'],
                'amount_due' => $quote['amount_due'],
                'no_of_extra_guests' => $extraGuestCount,
                'user_id' => null,
                'status' => 'Active',
            ]);

            ReservationDetail::query()->create([
                'reservation_id' => $reservation->reservation_id,
                'facility_id' => $facilityId,
                'rate_type' => $rateType,
                'check_in_date' => $checkInDate,
                'check_out_date' => $checkOutDate,
                'discount_id' => $discountId,
            ]);

            foreach ($extraGuests as $extraGuest) {
                ReservationExtraGuest::query()->create([
                    'reservation_id' => $reservation->reservation_id,
                    'first_name' => $extraGuest['first_name'],
                    'middle_name' => $extraGuest['middle_name'],
                    'last_name' => $extraGuest['last_name'],
                ]);
            }

            return $reservation->fresh([
                'guest.address',
                'details.facility.facilityType',
                'details.discount',
                'extraGuests',
            ]);
        });

        $this->confirmationEmailService->sendReservationCreated($reservation);

        return $reservation;
    }

    private function cleanExtraGuests(array $extraGuests): array
    {
        $clean = [];

        foreach ($extraGuests as $extraGuest) {
            $firstName = trim((string) ($extraGuest['first_name'] ?? ''));
            $middleName = trim((string) ($extraGuest['middle_name'] ?? ''));
            $lastName = trim((string) ($extraGuest['last_name'] ?? ''));

            if ($firstName === '' && $middleName === '' && $lastName === '') {
                continue;
            }

            if ($firstName === '' || $lastName === '') {
                throw new InvalidArgumentException('Each extra guest must have a first name and last name.');
            }

            $clean[] = [
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
            ];
        }

        return $clean;
    }

    private function newReservationReference(): string
    {
        do {
            $reference = 'R' . now()->format('ymdHis') . strtoupper(Str::random(4));
        } while (Reservation::query()->where('r_ref_no', $reference)->exists());

        return $reference;
    }
}

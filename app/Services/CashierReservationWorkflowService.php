<?php

namespace App\Services;

use App\FacilityProductCode;
use App\Models\Address;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\ReservationExtraGuest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CashierReservationWorkflowService
{
    public function __construct(
        private readonly FacilityAvailabilityService $availability,
        private readonly FacilityOccupancyService $occupancy,
        private readonly FacilityScheduleLockService $scheduleLock,
        private readonly ReservationQuoteService $quotes,
        private readonly DetailExtraGuestService $detailGuests,
        private readonly DecimalMoneyService $money,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Reservation
    {
        $data = Validator::make($data, $this->createRules())->validate();

        return DB::transaction(function () use ($data): Reservation {
            $facilityId = (int) $data['facility_id'];
            $facility = $this->scheduleLock->lockOne($facilityId)
                ->load(['facilityType', 'facilityProduct.productRates']);

            $this->guardCashier((int) $data['user_id']);

            if (
                $facility->facility_status !== 'Available'
                || (int) $facility->facility_type_id !== (int) $data['facility_type_id']
            ) {
                throw new InvalidArgumentException('The selected facility is not eligible for this reservation.');
            }

            $extraGuests = $this->cleanExtraGuests($data['extra_guests'] ?? []);
            $occupancy = $this->occupancy->forFacility($facility, (int) $data['total_guest_count']);
            $this->occupancy->assertNamedPaidExtraGuests($extraGuests, $occupancy['paid_extra_guest_count']);

            if (! $this->availability->isAvailable(
                $facilityId,
                (string) $data['check_in_date'],
                (string) $data['check_out_date'],
            )) {
                throw new InvalidArgumentException('Selected facility is no longer available for the selected date range.');
            }

            $quote = $this->quotes->quote(
                facilityId: $facilityId,
                rateType: (string) $data['rate_type'],
                checkInDate: (string) $data['check_in_date'],
                checkOutDate: (string) $data['check_out_date'],
                discountId: filled($data['discount_id'] ?? null) ? (int) $data['discount_id'] : null,
                totalGuestCount: (int) $data['total_guest_count'],
            );

            $address = Address::query()->firstOrCreate([
                'purok' => filled($data['purok'] ?? null) ? trim((string) $data['purok']) : null,
                'barangay' => filled($data['barangay'] ?? null) ? trim((string) $data['barangay']) : null,
                'city' => trim((string) $data['city']),
                'province' => trim((string) $data['province']),
            ]);

            $guest = Guest::query()->create([
                'first_name' => trim((string) $data['first_name']),
                'middle_name' => filled($data['middle_name'] ?? null) ? trim((string) $data['middle_name']) : null,
                'last_name' => trim((string) $data['last_name']),
                'contact_no' => trim((string) $data['contact_no']),
                'email' => filled($data['email'] ?? null) ? trim((string) $data['email']) : null,
                'address_id' => $address->address_id,
            ]);

            $reservation = Reservation::query()->create([
                'r_ref_no' => $this->newReference(),
                'guest_id' => $guest->guest_id,
                'reservation_date' => Carbon::today()->toDateString(),
                'total_price' => $quote['total_price'],
                'amount_due' => $quote['amount_due'],
                'no_of_extra_guests' => $quote['extra_guest_count'],
                'total_guest_count' => $quote['total_guest_count'],
                'user_id' => (int) $data['user_id'],
                'status' => 'Active',
            ]);

            $detail = ReservationDetail::query()->create([
                'reservation_id' => $reservation->reservation_id,
                'facility_id' => $facilityId,
                'rate_type' => $quote['rate_type'],
                'check_in_date' => $data['check_in_date'],
                'check_out_date' => $data['check_out_date'],
                'discount_id' => filled($data['discount_id'] ?? null) ? (int) $data['discount_id'] : null,
                ...$quote['detail_snapshot'],
            ]);

            $this->detailGuests->createForReservation($reservation, $detail, $extraGuests);

            return $reservation->fresh(['guest.address', 'details.facility.facilityType', 'extraGuests']);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function reschedule(int $reservationId, array $data): Reservation
    {
        $data = Validator::make($data, $this->rescheduleRules())->validate();

        return DB::transaction(function () use ($reservationId, $data): Reservation {
            $reservation = Reservation::query()->lockForUpdate()->find($reservationId);

            if (! $reservation) {
                throw new InvalidArgumentException('Reservation not found.');
            }
            $details = ReservationDetail::query()->where('reservation_id', $reservationId)->lockForUpdate()->get();
            $extraGuests = ReservationExtraGuest::query()->where('reservation_id', $reservationId)->lockForUpdate()->get();
            $payments = Payment::query()->where('reservation_id', $reservationId)->lockForUpdate()->get();
            $convertedBookings = Booking::query()->where('reservation_id', $reservationId)->lockForUpdate()->get();

            if ($details->count() !== 1) {
                throw new InvalidArgumentException('This reschedule workflow requires exactly one reservation detail.');
            }

            $detail = $details->sole();
            $facilities = $this->scheduleLock->lockMany([
                (int) $detail->facility_id,
                (int) $data['facility_id'],
            ])->load(['facilityType', 'facilityProduct.productRates'])->keyBy('facility_id');

            $this->guardCashier((int) $data['user_id']);

            if ($reservation->status !== 'Active' || $convertedBookings->isNotEmpty()) {
                throw new InvalidArgumentException('Only active, unconverted reservations can be rescheduled.');
            }

            $oldFacility = $facilities->get((int) $detail->facility_id);
            $newFacility = $facilities->get((int) $data['facility_id']);
            $expectedProductId = $detail->facility_product_id ?? $oldFacility?->facility_product_id;

            if (
                $newFacility === null
                || $newFacility->facility_status !== 'Available'
                || (int) $newFacility->facility_type_id !== (int) $oldFacility?->facility_type_id
                || $expectedProductId === null
                || (int) $newFacility->facility_product_id !== (int) $expectedProductId
            ) {
                throw new InvalidArgumentException('A reservation may only move to an eligible facility in the same product category.');
            }

            if (
                $detail->guest_count === null
                || $reservation->total_guest_count === null
                || (int) $detail->guest_count < 1
                || (int) $reservation->total_guest_count !== (int) $detail->guest_count
            ) {
                throw new InvalidArgumentException('The reservation guest count must be reviewed before rescheduling.');
            }

            $guestCount = (int) $reservation->total_guest_count;

            $occupancy = $this->occupancy->forFacility(
                $newFacility,
                $guestCount,
                $guestCount,
            );
            $isRoom = $newFacility->facilityProduct?->product_code === FacilityProductCode::RoomStandard;

            if ($isRoom) {
                if (
                    $extraGuests->contains(fn (ReservationExtraGuest $guest): bool => (int) $guest->reservation_id !== $reservationId
                        || (int) $guest->reservation_details_id !== (int) $detail->reservation_details_id)
                    || $extraGuests->count() !== (int) $reservation->no_of_extra_guests
                    || $extraGuests->count() !== $occupancy['paid_extra_guest_count']
                ) {
                    throw new InvalidArgumentException('Room extra-guest ownership and counts must be reconciled before rescheduling.');
                }
            } elseif (
                $extraGuests->isNotEmpty()
                || (int) $reservation->no_of_extra_guests !== 0
                || ! $this->money->equals($detail->extra_guest_fee ?? '0.00', '0.00')
            ) {
                throw new InvalidArgumentException('Cottage and function-hall reservations cannot contain room extra-guest charges or records.');
            }

            if (! $this->availability->isAvailable(
                (int) $newFacility->facility_id,
                (string) $data['check_in_date'],
                (string) $data['check_out_date'],
                $reservationId,
            )) {
                throw new InvalidArgumentException('Selected facility is not available for the new date range.');
            }

            $quote = $this->quotes->quote(
                facilityId: (int) $newFacility->facility_id,
                rateType: (string) $data['rate_type'],
                checkInDate: (string) $data['check_in_date'],
                checkOutDate: (string) $data['check_out_date'],
                discountId: filled($data['discount_id'] ?? null) ? (int) $data['discount_id'] : null,
                totalGuestCount: $guestCount,
            );
            $paidAmount = $this->money->add(...$payments
                ->where('payment_status', 'Verified')
                ->pluck('amount_paid')
                ->map(fn (mixed $amount): string => (string) $amount)
                ->all());

            if ($this->money->compare($paidAmount, $quote['total_price']) >= 0) {
                throw new InvalidArgumentException(
                    'The selected change would make this reservation fully paid or overpaid. Complete the appropriate booking/payment process before rescheduling.',
                );
            }

            $reservation->update([
                'total_price' => $quote['total_price'],
                'amount_due' => $this->money->subtract($quote['total_price'], $paidAmount),
                'no_of_extra_guests' => $quote['extra_guest_count'],
                'total_guest_count' => $quote['total_guest_count'],
            ]);
            $detail->update([
                'facility_id' => (int) $newFacility->facility_id,
                'rate_type' => $quote['rate_type'],
                'check_in_date' => $data['check_in_date'],
                'check_out_date' => $data['check_out_date'],
                'discount_id' => filled($data['discount_id'] ?? null) ? (int) $data['discount_id'] : null,
                ...$quote['detail_snapshot'],
            ]);

            return $reservation->fresh(['details.facility.facilityType', 'extraGuests', 'payments']);
        }, attempts: 3);
    }

    /** @return array<string, array<int, mixed>> */
    private function createRules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'min:1'],
            'first_name' => ['required', 'string', 'max:50'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:50'],
            'contact_no' => ['required', 'regex:/^09[0-9]{9}$/'],
            'email' => ['nullable', 'email', 'max:50'],
            'purok' => ['nullable', 'string', 'max:50'],
            'barangay' => ['nullable', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:50'],
            'province' => ['required', 'string', 'max:50'],
            'facility_type_id' => ['required', 'integer', 'min:1'],
            'facility_id' => ['required', 'integer', 'min:1'],
            'rate_type' => ['required', 'string', 'max:20'],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after_or_equal:check_in_date'],
            'discount_id' => ['nullable', 'integer', 'min:1'],
            'total_guest_count' => ['required', 'integer', 'min:1'],
            'extra_guests' => ['array'],
            'extra_guests.*.first_name' => ['required', 'string', 'max:50'],
            'extra_guests.*.middle_name' => ['nullable', 'string', 'max:50'],
            'extra_guests.*.last_name' => ['required', 'string', 'max:50'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function rescheduleRules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'min:1'],
            'facility_id' => ['required', 'integer', 'min:1'],
            'rate_type' => ['required', 'string', 'max:20'],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after_or_equal:check_in_date'],
            'discount_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function guardCashier(int $userId): void
    {
        $user = User::query()->with('role')->find($userId);

        if (
            ! $user
            || $user->status !== 'Active'
            || ! $user->role()->where('role_name', 'Cashier')->exists()
        ) {
            throw new InvalidArgumentException('Only a Cashier may create or reschedule reservations.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $extraGuests
     * @return array<int, array{first_name: string, middle_name: ?string, last_name: string}>
     */
    private function cleanExtraGuests(array $extraGuests): array
    {
        return collect($extraGuests)->map(fn (array $guest): array => [
            'first_name' => trim((string) $guest['first_name']),
            'middle_name' => filled($guest['middle_name'] ?? null) ? trim((string) $guest['middle_name']) : null,
            'last_name' => trim((string) $guest['last_name']),
        ])->all();
    }

    private function newReference(): string
    {
        do {
            $reference = 'R'.now()->format('ymd').strtoupper(Str::random(5));
        } while (Reservation::query()->where('r_ref_no', $reference)->exists());

        return $reference;
    }
}

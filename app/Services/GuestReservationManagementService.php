<?php

namespace App\Services;

use App\FacilityRateCode;
use App\Models\Facility;
use App\Models\FacilityType;
use App\Models\GuestVerificationOtp;
use App\Models\ProductRate;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class GuestReservationManagementService
{
    private const OTP_PURPOSE = 'reservation_manage';

    private const OTP_TTL_MINUTES = 10;

    private const MAX_OTP_ATTEMPTS = 5;

    public function __construct(
        private readonly ReservationQuoteService $quoteService,
        private readonly DiscountResolverService $discountResolver,
        private readonly FacilityOccupancyService $occupancy,
        private readonly FacilityScheduleLockService $scheduleLock,
        private readonly FacilityScheduleBlockService $scheduleBlocks,
        private readonly BookingAvailabilityService $availability,
        private readonly GuestConfirmationEmailService $confirmationEmailService,
        private readonly DetailExtraGuestService $detailGuests,
        private readonly FacilityProductConfigurationService $products,
        private readonly TransactionLedgerService $ledger,
    ) {}

    /** @return array{reservation_id:int, debug_otp:?string} */
    public function requestOtp(string $referenceNumber, string $email): array
    {
        $referenceNumber = strtoupper(trim($referenceNumber));
        $email = strtolower(trim($email));

        if ($referenceNumber === '' || $email === '') {
            throw new InvalidArgumentException('Reservation reference number and email are required.');
        }

        $reservation = $this->findManageableReservation($referenceNumber, $email);
        $otp = (string) random_int(100000, 999999);

        DB::transaction(function () use ($reservation, $email, $otp): void {
            GuestVerificationOtp::query()
                ->where('reservation_id', $reservation->reservation_id)
                ->where('email', $email)
                ->where('purpose', self::OTP_PURPOSE)
                ->whereNull('verified_at')
                ->update(['expires_at' => Carbon::now()->subMinute()]);

            GuestVerificationOtp::query()->create([
                'reservation_id' => $reservation->reservation_id,
                'email' => $email,
                'purpose' => self::OTP_PURPOSE,
                'otp_hash' => Hash::make($otp),
                'attempts' => 0,
                'expires_at' => Carbon::now()->addMinutes(self::OTP_TTL_MINUTES),
            ]);
        });

        $this->sendOtpEmail($email, $reservation->r_ref_no, $otp);

        return [
            'reservation_id' => (int) $reservation->reservation_id,
            'debug_otp' => app()->environment('local') ? $otp : null,
        ];
    }

    public function verifyOtp(int $reservationId, string $email, string $otp): Reservation
    {
        $email = strtolower(trim($email));
        $otp = trim($otp);

        if ($reservationId < 1 || $email === '' || $otp === '') {
            throw new InvalidArgumentException('OTP verification details are incomplete.');
        }

        $reservation = DB::transaction(function () use ($reservationId, $email, $otp): ?Reservation {
            $record = GuestVerificationOtp::query()
                ->where('reservation_id', $reservationId)
                ->where('email', $email)
                ->where('purpose', self::OTP_PURPOSE)
                ->whereNull('verified_at')
                ->latest('guest_verification_otp_id')
                ->lockForUpdate()
                ->first();

            if (! $record) {
                throw new InvalidArgumentException('No active OTP request was found. Please request a new OTP.');
            }

            /*if (Carbon::parse($record->expires_at)->isPast()) {
                throw new InvalidArgumentException('OTP expired. Please request a new OTP.');
            }*/

            if ($record->attempts >= self::MAX_OTP_ATTEMPTS) {
                throw new InvalidArgumentException('Too many OTP attempts. Please request a new OTP.');
            }

            $record->increment('attempts');

            if (! Hash::check($otp, $record->otp_hash)) {
                return null;
            }

            $record->update(['verified_at' => Carbon::now()]);

            return Reservation::query()
                ->with(['guest.address', 'details.facility.facilityType', 'details.discount', 'extraGuests', 'payments'])
                ->findOrFail($reservationId);
        });

        if ($reservation === null) {
            throw new InvalidArgumentException('Invalid OTP.');
        }

        return $reservation;
    }

    /** @return Collection<int, FacilityType> */
    public function facilityTypes(): Collection
    {
        return FacilityType::query()
            ->whereHas('facilities')
            ->orderBy('facility_type')
            ->get();
    }

    /** @return Collection<int, string> */
    public function rateTypesForFacilityType(?int $facilityTypeId): Collection
    {
        if (! $facilityTypeId) {
            return collect();
        }

        return ProductRate::query()
            ->select('tbl_facility_product_rate.rate_code')
            ->join('tbl_facility_product', 'tbl_facility_product.facility_product_id', '=', 'tbl_facility_product_rate.facility_product_id')
            ->where('tbl_facility_product.facility_type_id', $facilityTypeId)
            ->where('tbl_facility_product.is_active', true)
            ->where('tbl_facility_product_rate.is_active', true)
            ->distinct()
            ->pluck('tbl_facility_product_rate.rate_code')
            ->map(fn (FacilityRateCode|string $rateCode): string => $this->products->canonicalRateType(
                $rateCode instanceof FacilityRateCode ? $rateCode : FacilityRateCode::from($rateCode),
            ))
            ->unique()
            ->sort()
            ->values();
    }

    /** @return Collection<int, Facility> */
    public function availableFacilities(
        ?int $facilityTypeId,
        ?string $rateType,
        ?string $checkInDate,
        ?string $checkOutDate,
        ?int $ignoreReservationDetailsId = null
    ): Collection {
        if (! $facilityTypeId || blank($rateType) || blank($checkInDate) || blank($checkOutDate)) {
            return collect();
        }

        if ($checkOutDate <= $checkInDate) {
            return collect();
        }

        try {
            $rateCode = $this->products->canonicalRateCode($rateType);
        } catch (InvalidArgumentException) {
            return collect();
        }

        $facilities = Facility::query()
            ->with(['facilityType', 'facilityProduct', 'prices'])
            ->where('facility_type_id', $facilityTypeId)
            ->where('facility_status', 'Available')
            ->whereHas('facilityProduct.productRates', function ($query) use ($rateCode): void {
                $query->where('rate_code', $rateCode->value)->where('is_active', true);
            })
            ->orderBy('facility_name')
            ->get();

        return $facilities
            ->filter(fn (Facility $facility): bool => $this->availability->isFacilityAvailable(
                (int) $facility->facility_id,
                (string) $checkInDate,
                (string) $checkOutDate,
                null,
                $ignoreReservationDetailsId,
            ))
            ->values();
    }

    /** @return array<string, mixed>|null */
    public function quotePreview(
        ?int $facilityId,
        ?string $rateType,
        ?string $checkInDate,
        ?string $checkOutDate,
        int $extraGuestCount = 0,
        ?int $preferredDiscountId = null,
        ?int $totalGuestCount = null,
    ): ?array {
        if (! $facilityId || blank($rateType) || blank($checkInDate) || blank($checkOutDate)) {
            return null;
        }

        $discount = $this->discountResolver->resolveForFacility((int) $facilityId, (string) $checkInDate, $preferredDiscountId);

        return $this->quoteService->quote(
            facilityId: (int) $facilityId,
            rateType: (string) $rateType,
            checkInDate: (string) $checkInDate,
            checkOutDate: (string) $checkOutDate,
            discountId: $discount?->discount_id,
            extraGuestCount: $extraGuestCount,
            totalGuestCount: $totalGuestCount,
        );
    }

    /**
     * Update one or more facilities of a reservation in a single transaction.
     * Facilities that are not part of the request are left untouched, and the
     * reservation totals are recalculated from every active facility.
     *
     * @param  array{details: array<int|string, array<string, mixed>>}  $data
     */
    public function updateReservation(int $reservationId, array $data): Reservation
    {
        $changes = $this->normalizeChanges((array) ($data['details'] ?? []));

        $reservation = DB::transaction(function () use ($reservationId, $changes): Reservation {
            $reservation = Reservation::query()
                ->with(['details', 'payments'])
                ->lockForUpdate()
                ->findOrFail($reservationId);

            $this->guardActiveReservation($reservation);
            $this->guardNoVerifiedPayments($reservation);

            $editable = ReservationDetail::query()
                ->where('reservation_id', $reservation->reservation_id)
                ->orderBy('reservation_details_id')
                ->lockForUpdate()
                ->get()
                ->reject(fn (ReservationDetail $detail): bool => $this->isClosedDetail($detail))
                ->keyBy(fn (ReservationDetail $detail): int => (int) $detail->reservation_details_id);

            if ($editable->isEmpty()) {
                throw new InvalidArgumentException('Reservation has no facility details to update.');
            }

            foreach (array_keys($changes) as $detailId) {
                if (! $editable->has($detailId)) {
                    throw new InvalidArgumentException('One of the selected facilities can no longer be updated.');
                }
            }

            $facilities = $this->scheduleLock->lockMany([
                ...collect($changes)->pluck('facility_id')->values()->all(),
                ...collect(array_keys($changes))
                    ->map(fn (int $detailId): int => (int) $editable->get($detailId)->facility_id)
                    ->values()
                    ->all(),
            ])->keyBy('facility_id');

            // Validate and quote every changed facility before touching any data.
            $prepared = [];

            foreach ($changes as $detailId => $change) {
                $detail = $editable->get($detailId);
                $facilityId = $change['facility_id'];

                if ($facilities->get($facilityId)?->facility_status !== 'Available') {
                    throw new InvalidArgumentException('The selected facility is not available.');
                }

                $extraGuests = $this->cleanExtraGuests($change['extra_guests']);
                $occupancy = $this->occupancy->forFacilityId(
                    $facilityId,
                    $change['total_guest_count'],
                );
                $this->occupancy->assertNamedPaidExtraGuests(
                    $extraGuests,
                    $occupancy['paid_extra_guest_count'],
                );

                $discount = $this->discountResolver->resolveForFacility(
                    $facilityId,
                    $change['check_in_date'],
                    $detail->discount_id ? (int) $detail->discount_id : null,
                );

                $quote = $this->quoteService->quote(
                    facilityId: $facilityId,
                    rateType: $change['rate_type'],
                    checkInDate: $change['check_in_date'],
                    checkOutDate: $change['check_out_date'],
                    discountId: $discount?->discount_id,
                    totalGuestCount: $change['total_guest_count'],
                );

                $prepared[$detailId] = [
                    'detail' => $detail,
                    'change' => $change,
                    'discount' => $discount,
                    'quote' => $quote,
                    'extra_guests' => $extraGuests,
                ];
            }

            // Free every changed facility first so facilities can move between
            // details of the same reservation without blocking each other.
            $this->scheduleBlocks->releaseReservationDetails(
                collect($prepared)->pluck('detail')->values(),
            );

            foreach ($prepared as $item) {
                /** @var ReservationDetail $detail */
                $detail = $item['detail'];
                $change = $item['change'];
                $quote = $item['quote'];

                $this->scheduleBlocks->synchronizeReservationDetail($detail, [
                    'facility_id' => $change['facility_id'],
                    'check_in_date' => $change['check_in_date'],
                    'check_out_date' => $change['check_out_date'],
                    ...$quote['detail_snapshot'],
                ]);

                $detail->update([
                    'facility_id' => $change['facility_id'],
                    'rate_type' => $quote['rate_type'],
                    'check_in_date' => $change['check_in_date'],
                    'check_out_date' => $change['check_out_date'],
                    'discount_id' => $item['discount']?->discount_id,
                    ...$quote['detail_snapshot'],
                ]);

                // Only this facility's extra guests are replaced.
                $detail->extraGuests()->delete();

                if ($editable->count() === 1) {
                    // Older single-facility reservations may have unlinked guest rows.
                    $reservation->extraGuests()->whereNull('reservation_details_id')->delete();
                }

                $this->detailGuests->createForReservation(
                    $reservation,
                    $detail,
                    $item['extra_guests'],
                );
            }

            // Totals come from every active facility, not just the edited one.
            $fresh = $reservation->fresh(['details', 'payments']);
            $summary = $this->ledger->summaryForReservation($fresh);
            $active = $fresh->details->reject(
                fn (ReservationDetail $detail): bool => $this->isClosedDetail($detail),
            );
            $guestCounts = $active->pluck('guest_count');

            $reservation->update([
                'total_price' => $summary['total'],
                'amount_due' => $summary['balance'],
                'no_of_extra_guests' => $active->sum(
                    fn (ReservationDetail $detail): int => $this->paidExtraGuestCount($detail),
                ),
                'total_guest_count' => $guestCounts->contains(fn (mixed $count): bool => $count === null)
                    ? (int) $reservation->total_guest_count
                    : (int) $guestCounts->sum(),
            ]);

            return $reservation->fresh(['guest.address', 'details.facility.facilityType', 'details.discount', 'extraGuests', 'payments']);
        });

        $this->confirmationEmailService->sendReservationUpdated($reservation);

        return $reservation;
    }

    /** @return Collection<int, ReservationDetail> */
    public function editableDetails(Reservation $reservation): Collection
    {
        return $reservation->details
            ->reject(fn (ReservationDetail $detail): bool => $this->isClosedDetail($detail))
            ->values();
    }

    public function cancelReservation(int $reservationId, string $reason): Reservation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Cancellation reason is required.');
        }

        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('Cancellation reason must not exceed 500 characters.');
        }

        $reservation = DB::transaction(function () use ($reservationId, $reason): Reservation {
            $reservation = Reservation::query()
                ->with('payments')
                ->lockForUpdate()
                ->findOrFail($reservationId);

            $this->guardActiveReservation($reservation);
            $this->guardNoVerifiedPayments($reservation);

            $details = ReservationDetail::query()
                ->where('reservation_id', $reservation->reservation_id)
                ->lockForUpdate()
                ->get();

            if ($details->isNotEmpty()) {
                $this->scheduleLock->lockMany(
                    $details->pluck('facility_id')->all(),
                );
            }

            $reservation->update([
                'status' => 'Cancelled',
                'cancellation_reason' => $reason,
                'cancelled_at' => Carbon::today()->toDateString(),
            ]);

            $this->scheduleBlocks->releaseReservationDetails($details);

            GuestVerificationOtp::query()
                ->where('reservation_id', $reservation->reservation_id)
                ->where('purpose', self::OTP_PURPOSE)
                ->whereNull('verified_at')
                ->update(['expires_at' => Carbon::now()->subMinute()]);

            return $reservation->fresh(['guest.address', 'details.facility.facilityType', 'details.discount', 'extraGuests', 'payments']);
        });

        $this->confirmationEmailService->sendReservationCancelled($reservation);

        return $reservation;
    }

    private function isClosedDetail(ReservationDetail $detail): bool
    {
        return in_array((string) $detail->status, ['Cancelled', 'Converted', 'No-show'], true);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $details
     * @return array<int, array{facility_id: int, rate_type: string, check_in_date: string, check_out_date: string, total_guest_count: int, extra_guests: array<int, array<string, mixed>>}>
     */
    private function normalizeChanges(array $details): array
    {
        $changes = [];

        foreach ($details as $key => $change) {
            $detailId = (int) ($change['reservation_details_id'] ?? $key);
            $facilityId = (int) ($change['facility_id'] ?? 0);
            $rateType = trim((string) ($change['rate_type'] ?? ''));
            $checkIn = trim((string) ($change['check_in_date'] ?? ''));
            $checkOut = trim((string) ($change['check_out_date'] ?? ''));

            if ($detailId < 1 || $facilityId < 1 || $rateType === '' || $checkIn === '' || $checkOut === '') {
                throw new InvalidArgumentException('Complete the details of every facility before saving.');
            }

            $changes[$detailId] = [
                'facility_id' => $facilityId,
                'rate_type' => $rateType,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'total_guest_count' => (int) ($change['total_guest_count'] ?? 0),
                'extra_guests' => (array) ($change['extra_guests'] ?? []),
            ];
        }

        if ($changes === []) {
            throw new InvalidArgumentException('Reservation has no facility details to update.');
        }

        return $changes;
    }

    private function paidExtraGuestCount(ReservationDetail $detail): int
    {
        if ($detail->guest_count === null) {
            return (int) $detail->extraGuests()->count();
        }

        try {
            return (int) $this->occupancy->forFacilityId(
                (int) $detail->facility_id,
                (int) $detail->guest_count,
            )['paid_extra_guest_count'];
        } catch (InvalidArgumentException) {
            return (int) $detail->extraGuests()->count();
        }
    }

    private function findManageableReservation(string $referenceNumber, string $email): Reservation
    {
        $reservation = Reservation::query()
            ->with(['guest', 'payments'])
            ->where('r_ref_no', $referenceNumber)
            ->whereHas('guest', function ($query) use ($email): void {
                $query->whereRaw('LOWER(email) = ?', [$email]);
            })
            ->first();

        if (! $reservation) {
            throw new InvalidArgumentException('No reservation was found for the reference number and email.');
        }

        $this->guardActiveReservation($reservation);
        $this->guardNoVerifiedPayments($reservation);

        return $reservation;
    }

    private function guardActiveReservation(Reservation $reservation): void
    {
        if ((string) $reservation->status !== 'Active') {
            throw new InvalidArgumentException('Only active reservations can be managed online.');
        }
    }

    private function guardNoVerifiedPayments(Reservation $reservation): void
    {
        $hasVerifiedPayment = $reservation->payments()
            ->whereRaw('LOWER(payment_status) = ?', ['verified'])
            ->exists();

        if ($hasVerifiedPayment) {
            throw new InvalidArgumentException('This reservation already has verified payment. Please contact the cashier for changes.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $extraGuests
     * @return array<int, array{first_name: string, middle_name: ?string, last_name: string}>
     */
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

    private function sendOtpEmail(string $email, string $referenceNumber, string $otp): void
    {
        Mail::raw(
            "Your Olaer Spring Resort reservation OTP is {$otp}. Reference: {$referenceNumber}. This code expires in ".self::OTP_TTL_MINUTES.' minutes.',
            function ($message) use ($email): void {
                $message->to($email)->subject('Olaer Spring Resort Reservation OTP');
            }
        );
    }
}
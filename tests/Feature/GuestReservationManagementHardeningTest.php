<?php

namespace Tests\Feature;

use App\Models\GuestVerificationOtp;
use App\Models\Reservation;
use App\Services\GuestReservationManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class GuestReservationManagementHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_facilities_uses_canonical_availability_rules(): void
    {
        $typeId = $this->createFacilityType();
        $blockedFacilityId = $this->createFacility($typeId, 'Room A');
        $availableFacilityId = $this->createFacility($typeId, 'Room B');

        $bookingId = $this->createBooking('Booked', 0.00);
        $this->createBookingDetail(
            bookingId: $bookingId,
            facilityId: $blockedFacilityId,
            status: 'Transferred',
            checkIn: '2026-08-10',
            checkOut: '2026-08-12',
        );

        $facilities = app(GuestReservationManagementService::class)
            ->availableFacilities(
                $typeId,
                'Overnight',
                '2026-08-11',
                '2026-08-13',
            );

        $this->assertFalse(
            $facilities
                ->pluck('facility_id')
                ->contains($blockedFacilityId),
            'Transferred booking details must still block the target facility.',
        );

        $this->assertTrue(
            $facilities
                ->pluck('facility_id')
                ->contains($availableFacilityId),
        );
    }

    public function test_update_reservation_is_blocked_by_transferred_booking_detail(): void
    {
        Mail::fake();

        $typeId = $this->createFacilityType();
        $currentFacilityId = $this->createFacility($typeId, 'Room C');
        $blockedFacilityId = $this->createFacility($typeId, 'Room D');

        $reservationId = $this->createReservation(
            facilityId: $currentFacilityId,
            status: 'Active',
            amountDue: 1000.00,
            checkIn: '2026-09-10',
            checkOut: '2026-09-12',
        );

        $bookingId = $this->createBooking('Booked', 0.00);
        $this->createBookingDetail(
            bookingId: $bookingId,
            facilityId: $blockedFacilityId,
            status: 'Transferred',
            checkIn: '2026-09-10',
            checkOut: '2026-09-12',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Selected facility is not available',
        );

        app(GuestReservationManagementService::class)
            ->updateReservation(
                $reservationId,
                [
                    'facility_id' => $blockedFacilityId,
                    'rate_type' => 'Overnight',
                    'check_in_date' => '2026-09-10',
                    'check_out_date' => '2026-09-12',
                    'total_guest_count' => 4,
                    'extra_guests' => [],
                ],
            );
    }

    public function test_lowercase_verified_payment_blocks_online_reservation_changes(): void
    {
        $typeId = $this->createFacilityType();
        $facilityId = $this->createFacility($typeId, 'Room E');

        $reservationId = $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            amountDue: 0.00,
            checkIn: '2026-10-10',
            checkOut: '2026-10-12',
        );

        $this->createPaymentForReservation(
            reservationId: $reservationId,
            status: 'verified',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'already has verified payment',
        );

        app(GuestReservationManagementService::class)
            ->cancelReservation(
                $reservationId,
                'Guest requested cancellation.',
            );
    }

    public function test_cancelling_reservation_expires_active_otps(): void
    {
        Mail::fake();

        $typeId = $this->createFacilityType();
        $facilityId = $this->createFacility($typeId, 'Room F');

        $reservationId = $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            amountDue: 1000.00,
            checkIn: '2026-11-10',
            checkOut: '2026-11-12',
        );

        DB::table('tbl_guest_verification_otp')->insert([
            'reservation_id' => $reservationId,
            'email' => 'guest@example.test',
            'purpose' => 'reservation_manage',
            'otp_hash' => 'hashed-value',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'verified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(GuestReservationManagementService::class)
            ->cancelReservation(
                $reservationId,
                'Guest requested cancellation.',
            );

        $this->assertDatabaseHas('tbl_reservation', [
            'reservation_id' => $reservationId,
            'status' => 'Cancelled',
        ]);

        $expiresAt = DB::table('tbl_guest_verification_otp')
            ->where('reservation_id', $reservationId)
            ->value('expires_at');

        $this->assertTrue(
            now()->greaterThan($expiresAt),
            'Active OTPs should be expired after cancellation.',
        );
    }

    public function test_otp_requests_are_limited_to_three_per_ten_minutes_without_disclosing_existence(): void
    {
        Mail::fake();

        $typeId = $this->createFacilityType();
        $facilityId = $this->createFacility($typeId, 'Room OTP');
        $reservationId = $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            amountDue: 1000.00,
            checkIn: '2026-12-10',
            checkOut: '2026-12-12',
        );
        $reservation = Reservation::query()
            ->with('guest')
            ->findOrFail($reservationId);
        $reference = (string) $reservation->r_ref_no;
        $email = (string) $reservation->guest->email;
        $rateLimitKey = $this->reservationOtpRateLimitKey(
            $reference,
            $email,
        );
        RateLimiter::clear($rateLimitKey);

        $component = Livewire::test('guest.reservations.manage')
            ->set('referenceNumber', $reference)
            ->set('email', $email);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $component
                ->call('requestOtp')
                ->assertSet('otpRequested', true)
                ->assertSet('errorMessage', null)
                ->assertSet('debugOtp', null);
        }

        $this->assertGreaterThanOrEqual(
            599,
            RateLimiter::availableIn($rateLimitKey),
        );

        $component
            ->call('requestOtp')
            ->assertSet('otpRequested', false)
            ->assertSet(
                'errorMessage',
                'We could not send a one-time code. Check your details or try again later.',
            );

        $this->assertDatabaseCount('tbl_guest_verification_otp', 3);

        $latestOtp = GuestVerificationOtp::query()
            ->latest('guest_verification_otp_id')
            ->firstOrFail();
        $this->assertTrue(
            $latestOtp->expires_at->between(
                now()->addMinutes(9)->addSeconds(55),
                now()->addMinutes(10)->addSeconds(5),
            ),
        );

        $missingReference = 'R-NOT-FOUND-OTP';
        $missingEmail = 'missing-otp@example.test';
        RateLimiter::clear($this->reservationOtpRateLimitKey(
            $missingReference,
            $missingEmail,
        ));

        $missingComponent = Livewire::test('guest.reservations.manage')
            ->set('referenceNumber', $missingReference)
            ->set('email', $missingEmail);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $missingComponent->call('requestOtp');
        }

        $this->assertSame(
            $component->get('errorMessage'),
            $missingComponent->get('errorMessage'),
        );
    }

    public function test_otp_expiration_and_verification_attempt_limit_remain_enforced(): void
    {
        Mail::fake();

        $typeId = $this->createFacilityType();
        $facilityId = $this->createFacility(
            $typeId,
            'Room OTP Verification',
        );
        $reservationId = $this->createReservation(
            facilityId: $facilityId,
            status: 'Active',
            amountDue: 1000.00,
            checkIn: '2027-01-10',
            checkOut: '2027-01-12',
        );
        $reservation = Reservation::query()
            ->with('guest')
            ->findOrFail($reservationId);
        $service = app(GuestReservationManagementService::class);

        $service->requestOtp(
            (string) $reservation->r_ref_no,
            (string) $reservation->guest->email,
        );

        $otp = GuestVerificationOtp::query()
            ->latest('guest_verification_otp_id')
            ->firstOrFail();
        $otp->update(['otp_hash' => Hash::make('123456')]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $service->verifyOtp(
                    $reservationId,
                    (string) $reservation->guest->email,
                    '000000',
                );
                $this->fail('An invalid OTP was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Invalid OTP.', $exception->getMessage());
            }
        }

        $this->assertSame(5, (int) $otp->refresh()->attempts);

        try {
            $service->verifyOtp(
                $reservationId,
                (string) $reservation->guest->email,
                '123456',
            );
            $this->fail('The OTP attempt limit was bypassed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Too many OTP attempts. Please request a new OTP.',
                $exception->getMessage(),
            );
        }

        $otp->update([
            'attempts' => 0,
            'expires_at' => now()->subSecond(),
        ]);

        try {
            $service->verifyOtp(
                $reservationId,
                (string) $reservation->guest->email,
                '123456',
            );
            $this->fail('An expired OTP was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'OTP expired. Please request a new OTP.',
                $exception->getMessage(),
            );
        }
    }

    private function createFacilityType(): int
    {
        $existing = DB::table('tbl_facility_type')
            ->where('facility_type', 'Room')
            ->value('facility_type_id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return DB::table('tbl_facility_type')
            ->insertGetId([
                'facility_type' => 'Room',
            ]);
    }

    private function createFacility(int $typeId, string $name): int
    {
        $productId = DB::table('tbl_facility_product')
            ->where('product_code', 'ROOM_STANDARD')
            ->value('facility_product_id');

        if ($productId === null) {
            $productId = DB::table('tbl_facility_product')->insertGetId([
                'product_code' => 'ROOM_STANDARD',
                'facility_type_id' => $typeId,
                'display_name' => 'Standard Room',
                'size_label' => 'Standard',
                'schedule_policy' => 'overnight',
                'capacity_policy' => 'strict',
                'suggested_minimum' => null,
                'suggested_maximum' => null,
                'included_guest_count' => 4,
                'strict_maximum' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('tbl_facility_product_rate')->insert([
                'facility_product_id' => $productId,
                'rate_code' => 'OVERNIGHT',
                'display_name' => 'Overnight',
                'amount' => 1000.00,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $facilityId = DB::table('tbl_facility')
            ->insertGetId([
                'facility_name' => $name.' '.uniqid(),
                'facility_type_id' => $typeId,
                'facility_product_id' => $productId,
                'facility_size' => 'Standard',
                'facility_status' => 'Available',
                'capacity' => '10',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('tbl_facility_price')
            ->insert([
                'facility_id' => $facilityId,
                'rate_type' => 'Overnight',
                'facility_price' => 1000.00,
            ]);

        return $facilityId;
    }

    private function createReservation(
        int $facilityId,
        string $status,
        float $amountDue,
        string $checkIn,
        string $checkOut,
    ): int {
        $guestId = $this->createGuest();

        $payload = [
            'r_ref_no' => 'R'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'reservation_date' => now()->toDateString(),
            'total_price' => 1000.00,
            'amount_due' => $amountDue,
            'no_of_extra_guests' => 0,
            'user_id' => null,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('tbl_reservation', 'total_guest_count')) {
            $payload['total_guest_count'] = 4;
        }

        $reservationId = DB::table('tbl_reservation')
            ->insertGetId($payload);

        DB::table('tbl_reservation_details')->insert([
            'reservation_id' => $reservationId,
            'facility_id' => $facilityId,
            'rate_type' => 'Overnight',
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'discount_id' => null,
        ]);

        return $reservationId;
    }

    private function createBooking(string $status, float $amountDue): int
    {
        $guestId = $this->createGuest();

        $payload = [
            'b_ref_no' => 'B'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'guest_id' => $guestId,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_price' => 1000.00,
            'amount_due' => $amountDue,
            'user_id' => null,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('tbl_booking', 'total_guest_count')) {
            $payload['total_guest_count'] = 4;
        }

        return DB::table('tbl_booking')
            ->insertGetId($payload);
    }

    private function createBookingDetail(
        int $bookingId,
        int $facilityId,
        string $status,
        string $checkIn,
        string $checkOut,
    ): int {
        return DB::table('tbl_booking_details')
            ->insertGetId([
                'booking_id' => $bookingId,
                'facility_id' => $facilityId,
                'rate_type' => 'Overnight',
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'check_in_time' => '12:00:00',
                'status' => $status,
                'discount_id' => null,
                'user_id' => null,
            ]);
    }

    private function createGuest(): int
    {
        $addressId = DB::table('tbl_address')->insertGetId([
            'purok' => 'Purok 1',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'barangay' => 'Poblacion',
        ]);

        return DB::table('tbl_guest')->insertGetId([
            'first_name' => 'Guest',
            'middle_name' => null,
            'last_name' => 'Reservation',
            'contact_no' => '09123456789',
            'address_id' => $addressId,
            'email' => uniqid().'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPaymentForReservation(
        int $reservationId,
        string $status,
    ): int {
        $modeId = DB::table('tbl_mode_of_payment')
            ->where('mode_of_payment', 'Cash')
            ->value('mode_of_payment_id');

        if ($modeId === null) {
            $modeId = DB::table('tbl_mode_of_payment')
                ->insertGetId([
                    'mode_of_payment' => 'Cash',
                ]);
        }

        $payload = [
            'p_ref_no' => 'P'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'booking_id' => null,
            'reservation_id' => $reservationId,
            'entrance_slip_id' => null,
            'mode_of_payment_id' => $modeId,
            'reference_number' => null,
            'amount_paid' => 1000.00,
            'date_paid' => now()->toDateString(),
            'user_id' => null,
            'payment_status' => $status,
            'verified_by_user_id' => null,
            'verified_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('tbl_payment', 'rejection_reason')) {
            $payload['rejection_reason'] = null;
        }

        return DB::table('tbl_payment')
            ->insertGetId($payload);
    }

    private function reservationOtpRateLimitKey(
        string $referenceNumber,
        string $email,
    ): string {
        $identity = implode('|', [
            '127.0.0.1',
            strtoupper(trim($referenceNumber)),
            strtolower(trim($email)),
        ]);

        return 'reservation-management-otp:'.hash('sha256', $identity);
    }
}

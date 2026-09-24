<?php

namespace Tests\Feature;

use App\FacilityCapacityPolicy;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\Address;
use App\Models\Booking;
use App\Models\BookingDetail;
use App\Models\Facility;
use App\Models\Guest;
use App\Models\ModeOfPayment;
use App\Models\User;
use App\Services\PaymentWorkflowService;
use App\Services\TransactionLedgerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchTwoLedgerConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_room_snapshot_cannot_be_repriced_from_current_master_rate(): void
    {
        $this->seed(DatabaseSeeder::class);

        $room = Facility::query()
            ->where('facility_name', 'R-001')
            ->with('facilityProduct')
            ->firstOrFail();

        $address = Address::query()->create([
            'province' => 'South Cotabato',
            'city' => 'General Santos City',
            'barangay' => 'Dadiangas',
            'purok' => 'Test',
        ]);
        $guest = Guest::query()->create([
            'first_name' => 'Ledger',
            'middle_name' => null,
            'last_name' => 'Regression',
            'contact_no' => '09123456789',
            'email' => 'ledger-regression@example.test',
            'address_id' => $address->address_id,
        ]);

        $booking = Booking::query()->create([
            'b_ref_no' => 'B-LEDGER-001',
            'guest_id' => $guest->guest_id,
            'booking_date' => now()->toDateString(),
            'no_of_extra_guests' => 0,
            'total_guest_count' => 4,
            // Simulate the old R-001 -> R-010 corruption: parent was wrongly repriced
            // to the current master room rate even though the committed detail was 7,500.
            'total_price' => '2500.00',
            'amount_due' => '2500.00',
            'user_id' => null,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'status' => 'Booked',
        ]);

        BookingDetail::query()->create([
            'booking_id' => $booking->booking_id,
            'facility_id' => $room->facility_id,
            'facility_product_id' => $room->facility_product_id,
            'guest_count' => 4,
            'capacity_policy' => FacilityCapacityPolicy::Strict,
            'included_guest_count_snapshot' => 4,
            'strict_maximum_snapshot' => 10,
            'suggested_minimum_snapshot' => null,
            'suggested_maximum_snapshot' => null,
            'schedule_policy' => FacilitySchedulePolicy::Overnight,
            'rate_code' => FacilityRateCode::Overnight,
            'unit_rate' => '7500.00',
            'rate_type' => 'Overnight',
            'check_in_date' => now()->addDays(10)->toDateString(),
            'check_out_date' => now()->addDays(11)->toDateString(),
            'check_in_time' => null,
            'status' => 'Booked',
            'discount_id' => null,
            'discount_rate' => '0.000000',
            'user_id' => null,
            'base_price' => '7500.00',
            'discount_amount' => '0.00',
            'extra_guest_fee' => '0.00',
            'line_total' => '7500.00',
        ]);

        // The current master rate remains 2,500. Ledger must ignore it for history.
        $this->assertSame(
            '2500.00',
            (string) $room->prices()->where('rate_type', 'Overnight')->value('facility_price'),
        );

        $summary = app(TransactionLedgerService::class)->summaryForBooking($booking);
        $this->assertSame('7500.00', $summary['charges']);
        $this->assertSame('7500.00', $summary['balance']);

        $cashier = User::query()->where('username', 'cashier')->firstOrFail();
        $cash = ModeOfPayment::query()->where('mode_of_payment', 'Cash')->firstOrFail();

        app(PaymentWorkflowService::class)->recordCashierPayment([
            'target_type' => 'booking',
            'target_id' => $booking->booking_id,
            'amount_paid' => '1000.00',
            'mode_of_payment_id' => $cash->mode_of_payment_id,
            'reference_number' => '',
            'user_id' => $cashier->user_id,
        ]);

        $booking->refresh();

        $this->assertSame('6500.00', $booking->amount_due);
        $this->assertSame('2500.00', $booking->total_price);

        $summary = app(TransactionLedgerService::class)->summaryForBooking($booking);
        $this->assertSame('1000.00', $summary['verified_payments']);
        $this->assertSame('6500.00', $summary['balance']);
    }
}

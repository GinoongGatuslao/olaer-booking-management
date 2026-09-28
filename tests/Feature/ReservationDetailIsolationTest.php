<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Facility;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use App\Models\User;
use App\Services\ReservationFacilityOperationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationDetailIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_one_facility_does_not_mutate_its_sibling_detail(): void
    {
        $this->seed(DatabaseSeeder::class);

        $cashier = User::query()->where('username', 'cashier')->firstOrFail();
        $facilities = Facility::query()
            ->whereHas('facilityType', fn ($query) => $query->where('facility_type', 'Room'))
            ->whereNotNull('facility_product_id')
            ->with('facilityProduct')
            ->orderBy('facility_id')
            ->limit(2)
            ->get();

        $this->assertCount(2, $facilities);

        $address = Address::query()->create([
            'province' => 'South Cotabato',
            'city' => 'General Santos City',
            'barangay' => 'Dadiangas',
            'purok' => 'Isolation',
        ]);
        $guest = Guest::query()->create([
            'first_name' => 'Sibling',
            'middle_name' => null,
            'last_name' => 'Isolation',
            'contact_no' => '09123456789',
            'email' => 'sibling-isolation@example.test',
            'address_id' => $address->address_id,
        ]);

        $reservation = Reservation::query()->create([
            'r_ref_no' => 'R-ISOLATION-01',
            'guest_id' => $guest->guest_id,
            'reservation_date' => now()->toDateString(),
            'total_price' => '2000.00',
            'amount_due' => '2000.00',
            'no_of_extra_guests' => 0,
            'total_guest_count' => 8,
            'user_id' => $cashier->user_id,
            'status' => 'Active',
        ]);

        $checkIn = now()->addDays(20)->toDateString();
        $checkOut = now()->addDays(21)->toDateString();
        $details = $facilities->map(function (Facility $facility) use ($reservation, $checkIn, $checkOut): ReservationDetail {
            return ReservationDetail::query()->create([
                'reservation_id' => $reservation->reservation_id,
                'facility_id' => $facility->facility_id,
                'facility_product_id' => $facility->facility_product_id,
                'guest_count' => 4,
                'capacity_policy' => 'strict',
                'included_guest_count_snapshot' => 4,
                'strict_maximum_snapshot' => 10,
                'suggested_minimum_snapshot' => null,
                'suggested_maximum_snapshot' => null,
                'schedule_policy' => 'overnight',
                'rate_code' => 'OVERNIGHT',
                'unit_rate' => '1000.00',
                'rate_type' => 'Overnight',
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'status' => 'Reserved',
                'discount_id' => null,
                'base_price' => '1000.00',
                'discount_rate' => '0.000000',
                'discount_amount' => '0.00',
                'extra_guest_fee' => '0.00',
                'line_total' => '1000.00',
            ]);
        })->values();

        $target = $details[0];
        $sibling = $details[1];
        $siblingSnapshot = [
            'facility_id' => $sibling->facility_id,
            'check_in_date' => $sibling->check_in_date->toDateString(),
            'check_out_date' => $sibling->check_out_date->toDateString(),
            'line_total' => $sibling->line_total,
            'status' => $sibling->status,
        ];

        app(ReservationFacilityOperationService::class)->cancelDetail(
            (int) $target->reservation_details_id,
            'Guest no longer needs this room.',
            (int) $cashier->user_id,
        );

        $target->refresh();
        $sibling->refresh();
        $reservation->refresh();

        $this->assertSame('Cancelled', $target->status);
        $this->assertSame('Guest no longer needs this room.', $target->cancellation_reason);

        $this->assertSame($siblingSnapshot['facility_id'], $sibling->facility_id);
        $this->assertSame($siblingSnapshot['check_in_date'], $sibling->check_in_date->toDateString());
        $this->assertSame($siblingSnapshot['check_out_date'], $sibling->check_out_date->toDateString());
        $this->assertSame($siblingSnapshot['line_total'], $sibling->line_total);
        $this->assertSame($siblingSnapshot['status'], $sibling->status);

        $this->assertSame('1000.00', $reservation->total_price);
        $this->assertSame('1000.00', $reservation->amount_due);
        $this->assertDatabaseCount('tbl_transaction_credits', 0);
    }
}

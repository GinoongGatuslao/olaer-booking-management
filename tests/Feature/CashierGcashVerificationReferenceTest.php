<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CashierGcashVerificationReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gcash_reference_number_is_visible_in_review_panel(): void
    {
        $address = Address::query()->create([
            'province' => 'Laguna',
            'city' => 'Los Banos',
        ]);

        $guest = Guest::query()->create([
            'first_name' => 'Ana',
            'last_name' => 'Guest',
            'contact_no' => '09123456789',
            'address_id' => $address->address_id,
            'email' => 'ana@example.com',
        ]);

        $booking = Booking::query()->create([
            'b_ref_no' => 'B-TEST-001',
            'guest_id' => $guest->guest_id,
            'booking_date' => now()->toDateString(),
            'total_price' => 1500,
            'amount_due' => 1500,
            'status' => 'Pending',
        ]);

        $modeOfPayment = ModeOfPayment::query()->create([
            'mode_of_payment' => 'GCash',
        ]);

        $payment = Payment::query()->create([
            'p_ref_no' => 'PAY-TEST-001',
            'booking_id' => $booking->booking_id,
            'mode_of_payment_id' => $modeOfPayment->mode_of_payment_id,
            'reference_number' => 'GCASH-123456',
            'proof_of_payment_path' => 'proofs/gcash-test.jpg',
            'amount_paid' => 1500,
            'date_paid' => now()->toDateString(),
            'payment_status' => 'Pending',
        ]);

        Livewire::test('cashier.gcash-verifications.index')
            ->call('selectPayment', $payment->payment_id)
            ->assertSee('GCash reference number')
            ->assertSee('GCASH-123456')
            ->assertSee('Match this with the uploaded proof before verifying.');
    }
}

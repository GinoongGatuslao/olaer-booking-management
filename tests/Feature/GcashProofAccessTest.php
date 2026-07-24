<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\ModeOfPayment;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GcashProofAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_secure_gcash_proof_route_is_registered(): void
    {
        $this->assertTrue(
            Route::has('payments.gcash-proof'),
            'Merge routes/web.add-this.php into routes/web.php.',
        );
    }

    public function test_guest_cannot_open_a_gcash_proof(): void
    {
        Storage::fake('local');

        $payment = $this->createPaymentWithProof('local');

        $this->get(route('payments.gcash-proof', $payment))
            ->assertRedirect(route('login'));
    }

    public function test_security_guard_cannot_open_a_gcash_proof(): void
    {
        Storage::fake('local');

        $payment = $this->createPaymentWithProof('local');
        $security = $this->createUser('Security Guard');

        $this->actingAs($security)
            ->get(route('payments.gcash-proof', $payment))
            ->assertForbidden();
    }

    public function test_cashier_can_open_a_private_gcash_proof(): void
    {
        Storage::fake('local');

        $payment = $this->createPaymentWithProof('local');
        $cashier = $this->createUser('Cashier');

        $response = $this->actingAs($cashier)
            ->get(route('payments.gcash-proof', $payment));

        $response->assertOk();

        $cacheControl = strtolower(
            (string) $response->headers->get('Cache-Control'),
        );

        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString(
                $directive,
                $cacheControl,
                "Cache-Control is missing [{$directive}].",
            );
        }

        $response->assertHeader(
            'X-Content-Type-Options',
            'nosniff',
        );
    }

    public function test_legacy_public_proof_uses_authorized_fallback(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $payment = $this->createPaymentWithProof('public');
        $manager = $this->createUser('Manager');

        $this->actingAs($manager)
            ->get(route('payments.gcash-proof', $payment))
            ->assertOk();
    }

    public function test_invalid_or_traversal_path_is_not_served(): void
    {
        Storage::fake('local');

        $payment = $this->createPaymentWithProof(
            'local',
            '../secret.txt',
            writeFile: false,
        );

        $cashier = $this->createUser('Cashier');

        $this->actingAs($cashier)
            ->get(route('payments.gcash-proof', $payment))
            ->assertNotFound();
    }

    private function createPaymentWithProof(
        string $disk,
        string $path = 'gcash-proofs/test-proof.png',
        bool $writeFile = true,
    ): Payment {
        if ($writeFile) {
            Storage::disk($disk)->put(
                $path,
                'fake-image-content',
            );
        }

        $mode = ModeOfPayment::query()->firstOrCreate([
            'mode_of_payment' => 'GCash',
        ]);

        return Payment::query()->create([
            'p_ref_no' => 'P'.strtoupper(
                substr(md5(uniqid('', true)), 0, 12),
            ),
            'booking_id' => null,
            'reservation_id' => null,
            'entrance_slip_id' => null,
            'mode_of_payment_id' =>
                $mode->mode_of_payment_id,
            'reference_number' => '1234567890123',
            'proof_of_payment_path' => $path,
            'amount_paid' => 1000.00,
            'date_paid' => now()->toDateString(),
            'user_id' => null,
            'payment_status' => 'Pending',
            'verified_by_user_id' => null,
            'verified_at' => null,
        ]);
    }

    private function createUser(string $roleName): User
    {
        $role = Role::query()->firstOrCreate([
            'role_name' => $roleName,
        ]);

        $address = Address::query()->create([
            'purok' => 'Purok 1',
            'province' => 'Sultan Kudarat',
            'city' => 'Tacurong City',
            'barangay' => 'Test Barangay',
        ]);

        return User::query()->create([
            'first_name' => 'Test',
            'middle_name' => null,
            'last_name' => str_replace(' ', '', $roleName),
            'username' => strtolower(
                str_replace(' ', '_', $roleName),
            ).uniqid(),
            'password' => 'password',
            'email' => uniqid().'@example.test',
            'contact_no' => '09123456789',
            'status' => 'Active',
            'address_id' => $address->address_id,
            'role_id' => $role->role_id,
        ]);
    }
}

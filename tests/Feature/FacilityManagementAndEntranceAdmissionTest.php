<?php

namespace Tests\Feature;

use App\FacilityCapacityPolicy;
use App\FacilityProductCode;
use App\FacilityRateCode;
use App\FacilitySchedulePolicy;
use App\Models\EntranceFee;
use App\Models\FacilityProduct;
use App\Models\FacilityType;
use App\Models\ModeOfPayment;
use App\Models\ProductRate;
use App\Models\Role;
use App\Models\User;
use App\Services\EntranceSlipWorkflowService;
use App\Services\FacilityManagementService;
use App\Services\PaymentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FacilityManagementAndEntranceAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_facility_preset_bulk_creation_generates_unique_numbers_and_rates(): void
    {
        $type = FacilityType::query()->create(['facility_type' => 'Room']);
        $product = FacilityProduct::query()->create([
            'product_code' => FacilityProductCode::RoomStandard,
            'facility_type_id' => $type->facility_type_id,
            'display_name' => 'Standard Room',
            'size_label' => 'Standard',
            'schedule_policy' => FacilitySchedulePolicy::Overnight,
            'capacity_policy' => FacilityCapacityPolicy::Strict,
            'included_guest_count' => 4,
            'strict_maximum' => 10,
            'is_active' => true,
        ]);
        ProductRate::query()->create([
            'facility_product_id' => $product->facility_product_id,
            'rate_code' => FacilityRateCode::Overnight,
            'display_name' => 'Overnight',
            'amount' => '1500.00',
            'is_active' => true,
        ]);

        $created = app(FacilityManagementService::class)->createFromPreset(
            $product->facility_product_id,
            3,
            'Garden Room',
        );

        $this->assertSame(['ROO-STA-001', 'ROO-STA-002', 'ROO-STA-003'], $created->pluck('facility_number')->all());
        $this->assertSame(['Garden Room 1', 'Garden Room 2', 'Garden Room 3'], $created->pluck('facility_name')->all());
        $this->assertDatabaseCount('tbl_facility_price', 3);
    }

    public function test_entrance_slip_is_editable_by_creator_until_payment_and_cashier_admission(): void
    {
        $guard = $this->userWithRole('Security Guard');
        $cashier = $this->userWithRole('Cashier');
        EntranceFee::query()->create(['entrance_fee_name' => 'Adult', 'entrance_fee_price' => '100.00']);
        EntranceFee::query()->create(['entrance_fee_name' => 'Children', 'entrance_fee_price' => '80.00']);
        EntranceFee::query()->create(['entrance_fee_name' => 'Senior Citizen / PWD', 'entrance_fee_price' => '30.00']);
        $cash = ModeOfPayment::query()->create(['mode_of_payment' => 'Cash']);
        $workflow = app(EntranceSlipWorkflowService::class);
        $slip = $workflow->issue($this->entrancePayload($guard, 1));

        $slip = $workflow->updateBeforeAdmission(
            $slip->entrance_slip_id,
            $this->entrancePayload($guard, 2),
        );

        $this->assertSame('200.00', $slip->total_price);
        $this->assertSame('100.00', $slip->details->sole()->unit_rate_snapshot);
        $this->assertSame('200.00', $slip->details->sole()->line_total_snapshot);

        app(PaymentWorkflowService::class)->recordCashierPayment([
            'target_type' => 'entrance_slip',
            'target_id' => $slip->entrance_slip_id,
            'amount_paid' => $slip->amount_due,
            'mode_of_payment_id' => $cash->mode_of_payment_id,
            'reference_number' => '',
            'user_id' => $cashier->user_id,
        ]);
        $admitted = $workflow->admit($slip->entrance_slip_id, $cashier->user_id);

        $this->assertNotNull($admitted->admitted_at);
        $this->assertSame($cashier->user_id, $admitted->admitted_by_user_id);

        $this->expectException(InvalidArgumentException::class);
        $workflow->updateBeforeAdmission($slip->entrance_slip_id, $this->entrancePayload($guard, 1));
    }

    public function test_entrance_fee_model_rejects_zero_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EntranceFee::query()->create(['entrance_fee_name' => 'Invalid', 'entrance_fee_price' => '0.00']);
    }

    private function userWithRole(string $roleName): User
    {
        $role = Role::query()->create(['role_name' => $roleName]);

        return User::factory()->create(['role_id' => $role->role_id]);
    }

    /** @return array<string, mixed> */
    private function entrancePayload(User $guard, int $adults): array
    {
        return [
            'user_id' => $guard->user_id,
            'adult_count' => $adults,
            'children_count' => 0,
            'pwd_sc_count' => 0,
            'male_count' => $adults,
            'female_count' => 0,
            'tourist_count' => 0,
            'adult_discount_id' => null,
            'children_discount_id' => null,
            'pwd_sc_discount_id' => null,
            'adult_discounted_quantity' => 0,
            'children_discounted_quantity' => 0,
            'pwd_sc_discounted_quantity' => 0,
        ];
    }
}

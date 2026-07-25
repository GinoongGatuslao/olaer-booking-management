<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffNavigationRouteRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, string>  $routeNames
     */
    #[DataProvider('roleNavigationRoutes')]
    public function test_each_role_can_render_every_navigation_destination(
        string $roleName,
        array $routeNames,
    ): void {
        $role = Role::query()->create([
            'role_name' => $roleName,
        ]);

        $user = User::factory()->create([
            'role_id' => $role->role_id,
        ]);

        $this->actingAs($user);

        foreach ($routeNames as $routeName) {
            $response = $this->get(route($routeName));

            $this->assertSame(
                200,
                $response->getStatusCode(),
                sprintf(
                    'The [%s] navigation destination failed to render for role [%s].',
                    $routeName,
                    $roleName,
                ),
            );
        }
    }

    /**
     * @return array<string, array{string, array<int, string>}>
     */
    public static function roleNavigationRoutes(): array
    {
        return [
            'admin' => [
                'Admin',
                [
                    'admin.dashboard',
                    'admin.facilities.index',
                    'admin.amenities.index',
                    'admin.entrance-fees.index',
                    'admin.discounts.index',
                    'admin.fines.index',
                    'admin.reports.index',
                    'admin.activity-logs.index',
                    'admin.users.index',
                    'guest.home',
                    'profile.edit',
                ],
            ],
            'manager' => [
                'Manager',
                [
                    'admin.dashboard',
                    'admin.facilities.index',
                    'admin.amenities.index',
                    'admin.entrance-fees.index',
                    'admin.discounts.index',
                    'admin.fines.index',
                    'admin.reports.index',
                    'admin.activity-logs.index',
                    'admin.users.index',
                    'guest.home',
                    'profile.edit',
                ],
            ],
            'cashier' => [
                'Cashier',
                [
                    'cashier.dashboard',
                    'cashier.reservations.index',
                    'cashier.reservation-conversions.index',
                    'cashier.bookings.index',
                    'cashier.gcash-verifications.index',
                    'cashier.payments.index',
                    'cashier.entrance-slips.index',
                    'cashier.billings.index',
                    'cashier.check-ins.index',
                    'cashier.amenity-requests.index',
                    'cashier.check-outs.index',
                    'cashier.reports.index',
                    'cashier.notifications.index',
                    'cashier.action-center',
                    'guest.home',
                    'profile.edit',
                ],
            ],
            'maintenance' => [
                'Maintenance Staff',
                [
                    'maintenance.dashboard',
                    'maintenance.amenity-requests.index',
                    'maintenance.facility-inspections.index',
                    'maintenance.notifications.index',
                    'maintenance.action-center',
                    'guest.home',
                    'profile.edit',
                ],
            ],
            'security' => [
                'Security Guard',
                [
                    'security.dashboard',
                    'security.entrance-slips.create',
                    'guest.home',
                    'profile.edit',
                ],
            ],
        ];
    }

    public function test_maintenance_navigation_does_not_expose_cashier_only_booking_links(): void
    {
        $inspectionView = file_get_contents(
            resource_path(
                'views/livewire/maintenance/facility-inspections/index.blade.php',
            ),
        );

        $this->assertIsString($inspectionView);
        $this->assertStringNotContainsString(
            "route('cashier.bookings.show'",
            $inspectionView,
        );
    }

    public function test_maintenance_user_cannot_open_cashier_booking_workspace(): void
    {
        $role = Role::query()->create([
            'role_name' => 'Maintenance Staff',
        ]);

        $user = User::factory()->create([
            'role_id' => $role->role_id,
        ]);

        $this->actingAs($user)
            ->get(route('cashier.bookings.show', ['booking' => 1]))
            ->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StaffNavigationFoundationTest extends TestCase
{
    public function test_every_staff_navigation_destination_is_a_registered_route(): void
    {
        $routeNames = [
            'admin.dashboard',
            'admin.entrance-fees.index',
            'admin.discounts.index',
            'admin.facilities.index',
            'admin.amenities.index',
            'admin.fines.index',
            'admin.users.index',
            'admin.reports.index',
            'admin.activity-logs.index',
            'cashier.dashboard',
            'cashier.entrance-slips.index',
            'cashier.reservations.index',
            'cashier.bookings.index',
            'cashier.check-ins.index',
            'cashier.check-outs.index',
            'cashier.payments.index',
            'cashier.amenity-requests.index',
            'cashier.billings.index',
            'cashier.reports.index',
            'cashier.gcash-verifications.index',
            'cashier.reservation-conversions.index',
            'cashier.notifications.index',
            'cashier.action-center',
            'maintenance.dashboard',
            'maintenance.facility-inspections.index',
            'maintenance.amenity-requests.index',
            'maintenance.notifications.index',
            'maintenance.action-center',
            'security.dashboard',
            'security.entrance-slips.create',
            'guest.home',
            'profile.edit',
        ];

        foreach ($routeNames as $routeName) {
            $this->assertTrue(
                Route::has($routeName),
                sprintf('The staff navigation route [%s] is not registered.', $routeName),
            );
        }
    }

    public function test_authenticated_layout_uses_the_shared_navigation_foundation(): void
    {
        $layout = file_get_contents(
            resource_path('views/layouts/app.blade.php'),
        );

        $this->assertIsString($layout);

        foreach ([
            '<flux:sidebar',
            'collapsible',
            '<livewire:shared.staff-navigation />',
            '<x-staff-breadcrumbs',
            'id="main-content"',
            'Skip to main content',
            '<x-desktop-user-menu',
        ] as $requiredFragment) {
            $this->assertStringContainsString(
                $requiredFragment,
                $layout,
            );
        }
    }

    public function test_staff_layout_context_is_prepared_outside_blade(): void
    {
        $provider = file_get_contents(
            app_path('Providers/AppServiceProvider.php'),
        );

        $layout = file_get_contents(
            resource_path('views/layouts/app.blade.php'),
        );

        $this->assertIsString($provider);
        $this->assertIsString($layout);
        $this->assertStringContainsString(
            "View::composer(\n            'layouts.app'",
            $provider,
        );
        $this->assertStringContainsString(
            "'staffDashboardRoute' =>",
            $provider,
        );
        $this->assertStringNotContainsString(
            'loadMissing(',
            $layout,
        );
    }

    public function test_staff_navigation_is_a_class_based_volt_component(): void
    {
        $navigation = file_get_contents(
            resource_path('views/livewire/shared/staff-navigation.blade.php'),
        );

        $this->assertIsString($navigation);
        $this->assertStringContainsString(
            'use Livewire\Volt\Component;',
            $navigation,
        );
        $this->assertStringContainsString(
            'new class extends Component',
            $navigation,
        );
        $this->assertStringNotContainsString(
            'use function Livewire\Volt',
            $navigation,
        );
    }

    public function test_design_foundation_does_not_reference_flux_pro(): void
    {
        $styles = file_get_contents(
            resource_path('css/app.css'),
        );

        $this->assertIsString($styles);
        $this->assertStringNotContainsString(
            'flux-pro',
            $styles,
        );

        foreach ([
            '--color-brand-primary',
            '--color-brand-secondary',
            '--color-brand-surface',
            '--color-brand-success',
            '--color-brand-warning',
            '--color-brand-danger',
        ] as $token) {
            $this->assertStringContainsString(
                $token,
                $styles,
            );
        }
    }
}

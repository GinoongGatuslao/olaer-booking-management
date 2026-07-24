<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardVisualFoundationTest extends TestCase
{
    public function test_live_cashier_and_maintenance_routes_use_the_full_dashboards(): void
    {
        $routes = file_get_contents(
            base_path('routes/web.php'),
        );

        $this->assertIsString($routes);

        $this->assertStringContainsString(
            "Volt::route('/cashier/dashboard', 'cashier.dashboard')",
            $routes,
        );

        $this->assertStringContainsString(
            "Volt::route('/maintenance/dashboard', 'maintenance.dashboard')",
            $routes,
        );

        $this->assertStringNotContainsString(
            "Volt::route('/cashier/dashboard', 'cashier.dashboard.index')",
            $routes,
        );

        $this->assertStringNotContainsString(
            "Volt::route('/maintenance/dashboard', 'maintenance.dashboard.index')",
            $routes,
        );
    }

    public function test_role_dashboards_share_the_visual_foundation_and_poll_only_while_visible(): void
    {
        $dashboards = [
            'resources/views/livewire/admin/dashboard.blade.php',
            'resources/views/livewire/cashier/dashboard.blade.php',
            'resources/views/livewire/maintenance/dashboard.blade.php',
            'resources/views/livewire/security/dashboard.blade.php',
        ];

        foreach ($dashboards as $relativePath) {
            $content = file_get_contents(
                base_path($relativePath),
            );

            $this->assertIsString($content);

            $this->assertStringContainsString(
                'wire:poll.15s.visible',
                $content,
            );

            $this->assertStringContainsString(
                '<x-staff-page-header',
                $content,
            );

            $this->assertStringContainsString(
                '<x-dashboard-stat-card',
                $content,
            );
        }
    }

    public function test_dashboard_copy_uses_the_current_amenity_and_inventory_scope(): void
    {
        $maintenanceDashboard =
            file_get_contents(
                resource_path(
                    'views/livewire/maintenance/dashboard.blade.php',
                ),
            );

        $adminDashboard =
            file_get_contents(
                resource_path(
                    'views/livewire/admin/dashboard.blade.php',
                ),
            );

        $this->assertIsString(
            $maintenanceDashboard,
        );

        $this->assertIsString(
            $adminDashboard,
        );

        $this->assertStringNotContainsString(
            'Paid requests waiting',
            $maintenanceDashboard,
        );

        $this->assertStringNotContainsString(
            'No paid amenity requests',
            $maintenanceDashboard,
        );

        $this->assertStringContainsString(
            'warehouse stock is outside this system',
            $adminDashboard,
        );
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ShareStaffLayoutContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->loadMissing('role');
        $roleName = $user?->role?->role_name ?? 'Staff';

        View::share([
            'staffUser' => $user,
            'staffRoleName' => $roleName,
            'staffDisplayName' => $user?->full_name
                ?: $user?->username
                ?: 'Staff user',
            'staffDashboardRoute' => $this->dashboardRoute($roleName),
            'staffQuickAction' => $this->quickAction($roleName),
        ]);

        return $next($request);
    }

    private function dashboardRoute(string $roleName): string
    {
        return match ($roleName) {
            'Admin', 'Manager' => 'admin.dashboard',
            'Cashier' => 'cashier.dashboard',
            'Maintenance Staff' => 'maintenance.dashboard',
            'Security Guard' => 'security.dashboard',
            default => 'dashboard',
        };
    }

    /**
     * @return array{label: string, route: string, icon: string}|null
     */
    private function quickAction(string $roleName): ?array
    {
        return match ($roleName) {
            'Admin', 'Manager' => [
                'label' => 'View Reports',
                'route' => 'admin.reports.index',
                'icon' => 'chart-bar',
            ],
            'Cashier' => [
                'label' => 'Action Center',
                'route' => 'cashier.action-center',
                'icon' => 'bolt',
            ],
            'Maintenance Staff' => [
                'label' => 'Action Center',
                'route' => 'maintenance.action-center',
                'icon' => 'bolt',
            ],
            'Security Guard' => [
                'label' => 'New Entrance Slip',
                'route' => 'security.entrance-slips.create',
                'icon' => 'plus',
            ],
            default => null,
        };
    }
}

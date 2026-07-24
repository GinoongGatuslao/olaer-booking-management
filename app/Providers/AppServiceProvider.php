<?php

namespace App\Providers;

use App\Services\AuditObserverRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureStaffLayout();

        app(AuditObserverRegistry::class)
            ->register();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Provide presentation-only staff context without querying from Blade.
     */
    protected function configureStaffLayout(): void
    {
        View::composer(
            'layouts.app',
            function (ViewInstance $view): void {
                $user = Auth::user()?->loadMissing('role');
                $roleName = $user?->role?->role_name ?? 'Staff';

                $dashboardRoute = match ($roleName) {
                    'Admin', 'Manager' => 'admin.dashboard',
                    'Cashier' => 'cashier.dashboard',
                    'Maintenance Staff' => 'maintenance.dashboard',
                    'Security Guard' => 'security.dashboard',
                    default => 'dashboard',
                };

                $quickAction = match ($roleName) {
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

                $view->with([
                    'staffUser' => $user,
                    'staffRoleName' => $roleName,
                    'staffDisplayName' => $user?->full_name
                        ?: $user?->username
                        ?: 'Staff user',
                    'staffDashboardRoute' => $dashboardRoute,
                    'staffQuickAction' => $quickAction,
                ]);
            },
        );
    }
}

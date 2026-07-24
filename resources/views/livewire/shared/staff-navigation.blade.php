<?php

use App\Services\RealtimeDashboardService;
use App\Services\SecurityDashboardService;
use Livewire\Volt\Component;

new class extends Component
{
    public string $roleName = '';

    /**
     * @var array<string, int|float>
     */
    public array $counts = [];

    public function mount(
        RealtimeDashboardService $realtimeDashboard,
        SecurityDashboardService $securityDashboard,
    ): void {
        $user = auth()->user();
        $user?->loadMissing('role');

        $this->roleName = (string) ($user?->role?->role_name ?? '');

        $this->loadCounts(
            $realtimeDashboard,
            $securityDashboard,
        );
    }

    public function refreshNavigationCounts(
        RealtimeDashboardService $realtimeDashboard,
        SecurityDashboardService $securityDashboard,
    ): void {
        $this->loadCounts(
            $realtimeDashboard,
            $securityDashboard,
        );
    }

    private function loadCounts(
        RealtimeDashboardService $realtimeDashboard,
        SecurityDashboardService $securityDashboard,
    ): void {
        $this->counts = match ($this->roleName) {
            'Admin', 'Manager' => $realtimeDashboard->admin(),
            'Cashier' => $realtimeDashboard->cashier(),
            'Maintenance Staff' => $realtimeDashboard->maintenance(),
            'Security Guard' => $securityDashboard->overview((int) auth()->id()),
            default => [],
        };
    }

    public function badge(string $key): ?int
    {
        $count = (int) ($this->counts[$key] ?? 0);

        return $count > 0 ? $count : null;
    }

    public function cashierWorkCount(): ?int
    {
        $count = (int) ($this->counts['pending_gcash'] ?? 0)
            + (int) ($this->counts['pending_checkouts'] ?? 0)
            + (int) ($this->counts['unpaid_bookings'] ?? 0);

        return $count > 0 ? $count : null;
    }

    public function maintenanceWorkCount(): ?int
    {
        $count = (int) ($this->counts['inspection_requests'] ?? 0)
            + (int) ($this->counts['amenity_requests'] ?? 0);

        return $count > 0 ? $count : null;
    }
};

?>

<div wire:poll.30s.visible="refreshNavigationCounts" class="contents">
    <flux:sidebar.nav>
        @if (in_array($roleName, ['Admin', 'Manager'], true))
            <flux:sidebar.item
                icon="home"
                :href="route('admin.dashboard')"
                :current="request()->routeIs('admin.dashboard')"
                wire:navigate
            >
                Dashboard
            </flux:sidebar.item>

            <flux:sidebar.group
                expandable
                heading="Master Data"
                :expanded="request()->routeIs('admin.facilities.*', 'admin.amenities.*')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('admin.facilities.index')"
                    :current="request()->routeIs('admin.facilities.*')"
                    wire:navigate
                >
                    Facilities
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('admin.amenities.index')"
                    :current="request()->routeIs('admin.amenities.*')"
                    wire:navigate
                >
                    Amenities
                </flux:sidebar.item>
            </flux:sidebar.group>

            <flux:sidebar.group
                expandable
                heading="Financial Configuration"
                :expanded="request()->routeIs('admin.entrance-fees.*', 'admin.discounts.*', 'admin.fines.*')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('admin.entrance-fees.index')"
                    :current="request()->routeIs('admin.entrance-fees.*')"
                    wire:navigate
                >
                    Entrance Fees
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('admin.discounts.index')"
                    :current="request()->routeIs('admin.discounts.*')"
                    wire:navigate
                >
                    Discounts
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('admin.fines.index')"
                    :current="request()->routeIs('admin.fines.*')"
                    wire:navigate
                >
                    Fines
                </flux:sidebar.item>
            </flux:sidebar.group>

            <flux:sidebar.group
                expandable
                heading="Reports"
                :expanded="request()->routeIs('admin.reports.*', 'admin.activity-logs.*')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('admin.reports.index')"
                    :current="request()->routeIs('admin.reports.*')"
                    wire:navigate
                >
                    Management Reports
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('admin.activity-logs.index')"
                    :current="request()->routeIs('admin.activity-logs.*')"
                    wire:navigate
                >
                    Activity Logs
                </flux:sidebar.item>
            </flux:sidebar.group>

            <flux:sidebar.group
                expandable
                heading="Administration"
                :expanded="request()->routeIs('admin.users.*')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('admin.users.index')"
                    :current="request()->routeIs('admin.users.*')"
                    wire:navigate
                >
                    Staff Users
                </flux:sidebar.item>
            </flux:sidebar.group>
        @elseif ($roleName === 'Cashier')
            <flux:sidebar.item
                icon="home"
                :href="route('cashier.dashboard')"
                :current="request()->routeIs('cashier.dashboard')"
                wire:navigate
            >
                Dashboard
            </flux:sidebar.item>

            <flux:sidebar.group
                expandable
                heading="Front Desk"
                :expanded="request()->routeIs('cashier.reservations.*', 'cashier.reservation-conversions.*', 'cashier.bookings.*')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('cashier.reservations.index')"
                    :current="request()->routeIs('cashier.reservations.*')"
                    wire:navigate
                >
                    Reservations
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('cashier.reservation-conversions.index')"
                    :current="request()->routeIs('cashier.reservation-conversions.*')"
                    wire:navigate
                >
                    Reservation Conversion
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('cashier.bookings.index')"
                    :current="request()->routeIs('cashier.bookings.*')"
                    wire:navigate
                >
                    Bookings
                </flux:sidebar.item>
            </flux:sidebar.group>

            <flux:sidebar.group
                expandable
                heading="Payments"
                :expanded="request()->routeIs('cashier.gcash-verifications.*', 'cashier.payments.*', 'cashier.entrance-slips.*', 'cashier.billings.*')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('cashier.gcash-verifications.index')"
                    :current="request()->routeIs('cashier.gcash-verifications.*')"
                    :badge="$this->badge('pending_gcash')"
                    wire:navigate
                >
                    GCash Verification
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('cashier.payments.index')"
                    :current="request()->routeIs('cashier.payments.*')"
                    wire:navigate
                >
                    Record Payment
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('cashier.entrance-slips.index')"
                    :current="request()->routeIs('cashier.entrance-slips.*')"
                    wire:navigate
                >
                    Entrance Slips
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('cashier.billings.index')"
                    :current="request()->routeIs('cashier.billings.*')"
                    wire:navigate
                >
                    Billing Statements
                </flux:sidebar.item>
            </flux:sidebar.group>

            <flux:sidebar.group
                expandable
                heading="Guest Stay"
                :expanded="request()->routeIs('cashier.check-ins.*', 'cashier.amenity-requests.*')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('cashier.check-ins.index')"
                    :current="request()->routeIs('cashier.check-ins.*')"
                    wire:navigate
                >
                    Check-in
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('cashier.amenity-requests.index')"
                    :current="request()->routeIs('cashier.amenity-requests.*')"
                    wire:navigate
                >
                    Amenity Requests
                </flux:sidebar.item>
            </flux:sidebar.group>

            <flux:sidebar.group
                expandable
                heading="Checkout"
                :expanded="request()->routeIs('cashier.check-outs.*')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('cashier.check-outs.index')"
                    :current="request()->routeIs('cashier.check-outs.*')"
                    :badge="$this->badge('pending_checkouts')"
                    wire:navigate
                >
                    Checkout Queue
                </flux:sidebar.item>
            </flux:sidebar.group>

            <flux:sidebar.group
                expandable
                heading="Reports & Work"
                :expanded="request()->routeIs('cashier.reports.*', 'cashier.notifications.*', 'cashier.action-center')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('cashier.reports.index')"
                    :current="request()->routeIs('cashier.reports.*')"
                    wire:navigate
                >
                    Reports
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('cashier.notifications.index')"
                    :current="request()->routeIs('cashier.notifications.*')"
                    :badge="$this->cashierWorkCount()"
                    wire:navigate
                >
                    Notifications
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('cashier.action-center')"
                    :current="request()->routeIs('cashier.action-center')"
                    :badge="$this->cashierWorkCount()"
                    wire:navigate
                >
                    Action Center
                </flux:sidebar.item>
            </flux:sidebar.group>
        @elseif ($roleName === 'Maintenance Staff')
            <flux:sidebar.item
                icon="home"
                :href="route('maintenance.dashboard')"
                :current="request()->routeIs('maintenance.dashboard')"
                wire:navigate
            >
                Dashboard
            </flux:sidebar.item>

            <flux:sidebar.group
                expandable
                heading="Work Queue"
                :expanded="request()->routeIs('maintenance.amenity-requests.*', 'maintenance.facility-inspections.*')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('maintenance.amenity-requests.index')"
                    :current="request()->routeIs('maintenance.amenity-requests.*')"
                    :badge="$this->badge('amenity_requests')"
                    wire:navigate
                >
                    Amenity Requests
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('maintenance.facility-inspections.index')"
                    :current="request()->routeIs('maintenance.facility-inspections.*')"
                    :badge="$this->badge('inspection_requests')"
                    wire:navigate
                >
                    Facility Inspections
                </flux:sidebar.item>
            </flux:sidebar.group>

            <flux:sidebar.group
                expandable
                heading="Workspace"
                :expanded="request()->routeIs('maintenance.notifications.*', 'maintenance.action-center')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('maintenance.notifications.index')"
                    :current="request()->routeIs('maintenance.notifications.*')"
                    :badge="$this->maintenanceWorkCount()"
                    wire:navigate
                >
                    Notifications
                </flux:sidebar.item>
                <flux:sidebar.item
                    :href="route('maintenance.action-center')"
                    :current="request()->routeIs('maintenance.action-center')"
                    :badge="$this->maintenanceWorkCount()"
                    wire:navigate
                >
                    Action Center
                </flux:sidebar.item>
            </flux:sidebar.group>
        @elseif ($roleName === 'Security Guard')
            <flux:sidebar.item
                icon="home"
                :href="route('security.dashboard')"
                :current="request()->routeIs('security.dashboard')"
                :badge="$this->badge('my_unpaid_slips_today')"
                wire:navigate
            >
                Dashboard
            </flux:sidebar.item>

            <flux:sidebar.group
                expandable
                heading="Entrance Operations"
                :expanded="request()->routeIs('security.entrance-slips.*')"
                class="grid"
            >
                <flux:sidebar.item
                    :href="route('security.entrance-slips.create')"
                    :current="request()->routeIs('security.entrance-slips.*')"
                    wire:navigate
                >
                    Create Entrance Slip
                </flux:sidebar.item>
            </flux:sidebar.group>
        @else
            <flux:sidebar.item
                icon="home"
                :href="route('dashboard')"
                :current="request()->routeIs('dashboard')"
                wire:navigate
            >
                Dashboard
            </flux:sidebar.item>
        @endif
    </flux:sidebar.nav>
</div>

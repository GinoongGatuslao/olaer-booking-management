@php
    $routeName = request()->route()?->getName();
    $roleName = auth()->user()?->role?->role_name;

    $dashboardRoute = match ($roleName) {
        'Admin', 'Manager' => 'admin.dashboard',
        'Cashier' => 'cashier.dashboard',
        'Maintenance Staff' => 'maintenance.dashboard',
        'Security Guard' => 'security.dashboard',
        default => 'dashboard',
    };

    $routeLabels = [
        'admin.dashboard' => ['Dashboard'],
        'admin.entrance-fees.index' => ['Financial Configuration', 'Entrance Fees'],
        'admin.discounts.index' => ['Financial Configuration', 'Discounts'],
        'admin.facilities.index' => ['Master Data', 'Facilities'],
        'admin.amenities.index' => ['Master Data', 'Amenities'],
        'admin.fines.index' => ['Financial Configuration', 'Fines'],
        'admin.users.index' => ['Administration', 'Staff Users'],
        'admin.reports.index' => ['Reports', 'Management Reports'],
        'admin.activity-logs.index' => ['System Activity', 'Activity Logs'],

        'cashier.dashboard' => ['Dashboard'],
        'cashier.entrance-slips.index' => ['Payments', 'Entrance Slips'],
        'cashier.reservations.index' => ['Front Desk', 'Reservations'],
        'cashier.reservation-conversions.index' => ['Front Desk', 'Reservation Conversion'],
        'cashier.bookings.index' => ['Front Desk', 'Bookings'],
        'cashier.bookings.show' => ['Front Desk', 'Bookings', 'Booking Details'],
        'cashier.check-ins.index' => ['Guest Stay', 'Check-in'],
        'cashier.check-outs.index' => ['Checkout', 'Checkout Queue'],
        'cashier.payments.index' => ['Payments', 'Record Payment'],
        'cashier.amenity-requests.index' => ['Guest Stay', 'Amenity Requests'],
        'cashier.billings.index' => ['Payments', 'Billing Statements'],
        'cashier.reports.index' => ['Reports', 'Cashier Reports'],
        'cashier.gcash-verifications.index' => ['Payments', 'GCash Verification'],
        'cashier.notifications.index' => ['Work Queue', 'Notifications'],
        'cashier.action-center' => ['Work Queue', 'Action Center'],

        'maintenance.dashboard' => ['Dashboard'],
        'maintenance.facility-inspections.index' => ['Work Queue', 'Facility Inspections'],
        'maintenance.amenity-requests.index' => ['Work Queue', 'Amenity Requests'],
        'maintenance.notifications.index' => ['Workspace', 'Notifications'],
        'maintenance.action-center' => ['Workspace', 'Action Center'],

        'security.dashboard' => ['Dashboard'],
        'security.entrance-slips.create' => ['Entrance Operations', 'Create Entrance Slip'],

        'profile.edit' => ['Account', 'Profile'],
        'appearance.edit' => ['Account', 'Appearance'],
        'security.edit' => ['Account', 'Security'],
    ];

    $labels = $routeLabels[$routeName] ?? ['Workspace'];
@endphp

<flux:breadcrumbs {{ $attributes }}>
    @if ($routeName !== $dashboardRoute)
        <flux:breadcrumbs.item
            :href="route($dashboardRoute)"
            icon="home"
            wire:navigate
        />
    @endif

    @foreach ($labels as $label)
        <flux:breadcrumbs.item wire:key="breadcrumb-{{ str($label)->slug() }}">
            {{ $label }}
        </flux:breadcrumbs.item>
    @endforeach
</flux:breadcrumbs>

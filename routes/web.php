<?php

use App\Http\Controllers\GcashProofController;
use App\Http\Controllers\PrintDocumentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Volt::route('/login', 'auth.login')
    ->middleware('guest')
    ->name('login');

Route::post('/logout', function (Request $request) {
    Auth::guard('web')->logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Volt::route('/forgot-password', 'auth.forgot-password')
    ->middleware('guest')
    ->name('password.request');

Volt::route('/dashboard', 'dashboard')
    ->middleware(['auth', 'active'])
    ->name('dashboard');




// Admin Routes
Volt::route('/admin/dashboard', 'admin.dashboard')
    ->middleware(['auth', 'active', 'role:Admin,Manager'])
    ->name('admin.dashboard');

Volt::route('/admin/entrance-fees', 'admin.entrance-fees.index')
    ->middleware(['auth', 'active', 'role:Admin,Manager'])
    ->name('admin.entrance-fees.index');

Volt::route('/admin/discounts', 'admin.discounts.index')
    ->middleware(['auth', 'active', 'role:Admin,Manager'])
    ->name('admin.discounts.index');

Volt::route('/admin/facilities', 'admin.facilities.index')
    ->middleware(['auth', 'active', 'role:Admin,Manager'])
    ->name('admin.facilities.index');

Volt::route('/admin/amenities', 'admin.amenities.index')
    ->middleware(['auth', 'active', 'role:Admin,Manager'])
    ->name('admin.amenities.index');

Volt::route('/admin/fines', 'admin.fines.index')
    ->middleware(['auth', 'active', 'role:Admin,Manager'])
    ->name('admin.fines.index');

Volt::route('/admin/users', 'admin.users.index')
    ->middleware(['auth', 'active', 'role:Admin,Manager'])
    ->name('admin.users.index');

Volt::route('/admin/reports', 'admin.reports.index')
    ->middleware(['auth', 'active', 'role:Admin,Manager'])
    ->name('admin.reports.index');

Volt::route('/admin/activity-logs', 'admin.activity-logs.index')
    ->middleware(['auth', 'active', 'role:Admin,Manager'])
    ->name('admin.activity-logs.index');


// Cashier Routes
Volt::route('/cashier/dashboard', 'cashier.dashboard')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.dashboard');

Volt::route('/cashier/entrance-slips', 'cashier.entrance-slips.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.entrance-slips.index');

Volt::route('/cashier/reservations', 'cashier.reservations.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.reservations.index');

Volt::route('/cashier/bookings', 'cashier.bookings.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.bookings.index');

Volt::route('/cashier/check-ins', 'cashier.check-ins.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.check-ins.index');

Volt::route('/cashier/check-outs', 'cashier.check-outs.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.check-outs.index');

Volt::route('/cashier/payments', 'cashier.payments.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.payments.index');

Volt::route('/cashier/amenity-requests', 'cashier.amenity-requests.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.amenity-requests.index');

Volt::route('/cashier/billings', 'cashier.billings.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.billings.index');

Volt::route('/cashier/reports', 'cashier.reports.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.reports.index');

Volt::route('/cashier/gcash-verifications', 'cashier.gcash-verifications.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.gcash-verifications.index');

Volt::route('/cashier/reservation-conversions', 'cashier.reservation-conversions.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.reservation-conversions.index');

Volt::route('/cashier/notifications', 'cashier.notifications.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.notifications.index');

Volt::route('/cashier/action-center', 'cashier.action-center.index')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.action-center');

Volt::route('/cashier/bookings/{booking}/details', 'cashier.bookings.show')
    ->whereNumber('booking')
    ->middleware(['auth', 'active', 'role:Cashier'])
    ->name('cashier.bookings.show');

// Maintenance Staff Routes
Volt::route('/maintenance/dashboard', 'maintenance.dashboard')
    ->middleware(['auth', 'active', 'role:Maintenance Staff'])
    ->name('maintenance.dashboard');

Volt::route('/maintenance/facility-inspections', 'maintenance.facility-inspections.index')
    ->middleware(['auth', 'active', 'role:Maintenance Staff'])
    ->name('maintenance.facility-inspections.index');

Volt::route('/maintenance/amenity-requests', 'maintenance.amenity-requests.index')
    ->middleware(['auth', 'active', 'role:Maintenance Staff'])
    ->name('maintenance.amenity-requests.index');

Volt::route('/maintenance/notifications', 'maintenance.notifications.index')
    ->middleware(['auth', 'active', 'role:Maintenance Staff'])
    ->name('maintenance.notifications.index');

Volt::route('/maintenance/action-center', 'maintenance.action-center.index')
    ->middleware(['auth', 'active', 'role:Maintenance Staff'])
    ->name('maintenance.action-center');



// Security Guard Routes
Volt::route('/security/dashboard', 'security.dashboard')
    ->middleware(['auth', 'active', 'role:Security Guard'])
    ->name('security.dashboard');

Volt::route('/security/entrance-slips/create', 'security.entrance-slips.create')
    ->middleware(['auth', 'active', 'role:Security Guard'])
    ->name('security.entrance-slips.create');



//Guest Routes
Volt::route('/', 'guest.home')
    ->name('guest.home');

Volt::route('/reserve', 'guest.reservations.create')
    ->name('guest.reservations.create');

Volt::route('/reservation/success', 'guest.reservations.success')
    ->name('guest.reservations.success');

Volt::route('/book', 'guest.bookings.create')
    ->name('guest.bookings.create');

Volt::route('/reservation/manage', 'guest.reservations.manage')
    ->name('guest.reservations.manage');

Volt::route('/confirmation', 'guest.confirmations.lookup')
    ->name('guest.confirmations.lookup');


//secure gcash routes
Route::get(
    '/secure/gcash-proofs/{payment}',
    GcashProofController::class,
)
    ->whereNumber('payment')
    ->middleware([
        'auth',
        'active',
        'role:Admin,Manager,Cashier',
    ])
    ->name('payments.gcash-proof');


//Print
Route::middleware(['auth', 'active'])->prefix('print')->name('print.')->group(function () {
    Route::get('/entrance-slip/{entranceSlip}', [PrintDocumentController::class, 'entranceSlip'])
        ->middleware('role:Admin,Manager,Cashier,Security Guard')
        ->name('entrance-slip');

    Route::get('/reservation/{reservation}', [PrintDocumentController::class, 'reservationConfirmation'])
        ->middleware('role:Admin,Manager,Cashier')
        ->name('reservation');

    Route::get('/booking/{booking}', [PrintDocumentController::class, 'bookingConfirmation'])
        ->middleware('role:Admin,Manager,Cashier')
        ->name('booking');

    Route::get('/payment/{payment}', [PrintDocumentController::class, 'paymentReceipt'])
        ->middleware('role:Admin,Manager,Cashier')
        ->name('payment');

    Route::get('/billing/{booking}', [PrintDocumentController::class, 'billingStatement'])
        ->middleware('role:Admin,Manager,Cashier')
        ->name('billing');
});

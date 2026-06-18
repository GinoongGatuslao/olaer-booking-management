<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome')->name('home');

Volt::route('/login', 'auth.login')
    ->middleware('guest')
    ->name('login');

Route::post('/logout', function (Request $request) {
    Auth::guard('web')->logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

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
// Maintenance Staff Routes
Volt::route('/maintenance/dashboard', 'maintenance.dashboard')
    ->middleware(['auth', 'active', 'role:Maintenance Staff'])
    ->name('maintenance.dashboard');

// Security Guard Routes
Volt::route('/security/dashboard', 'security.dashboard')
    ->middleware(['auth', 'active', 'role:Security Guard'])
    ->name('security.dashboard');

Volt::route('/security/entrance-slips/create', 'security.entrance-slips.create')
    ->middleware(['auth', 'active', 'role:Security Guard'])
    ->name('security.entrance-slips.create');

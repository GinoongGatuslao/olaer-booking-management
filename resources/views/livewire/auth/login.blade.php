<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use function Livewire\Volt\{layout, mount, rules, state, title};

layout('layouts.guest');
title('Login - Olaer Spring Resort');

state([
    'username' => '',
    'password' => '',
    'remember' => false,
]);

mount(function () {
    // Pre-fill the username (and tick "Remember me") when it was remembered before.
    $remembered = (string) request()->cookie('remembered_username', '');

    if ($remembered !== '') {
        $this->username = $remembered;
        $this->remember = true;
    }
});

rules([
    'username' => ['required', 'string'],
    'password' => ['required', 'string'],
]);

$login = function () {
    $this->validate();

    $credentials = [
        'username' => $this->username,
        'password' => $this->password,
    ];

    // Check the credentials without opening a session yet.
    if (! Auth::validate($credentials)) {
        throw ValidationException::withMessages([
            'username' => 'The username or password is incorrect.',
        ]);
    }

    $user = Auth::getLastAttempted()->loadMissing('role');

    if ($user->status !== 'Active') {
        throw ValidationException::withMessages([
            'username' => 'Your account is inactive. Please contact the administrator.',
        ]);
    }

    // Newest login wins. A fresh remember token cancels "remember me" on every other device
    // (a plain query is used so the audit log is not filled with token changes).
    $rememberToken = Str::random(60);

    DB::table('tbl_user')
        ->where('user_id', $user->getAuthIdentifier())
        ->update(['remember_token' => $rememberToken]);

    $user->setRememberToken($rememberToken);

    Auth::login($user, (bool) $this->remember);
    session()->regenerate();

    // This session is now the only one allowed to use the account; older ones are signed
    // out by EnforceSingleSession on their next request.
    if (Schema::hasColumn('tbl_user', 'current_session_id')) {
        DB::table('tbl_user')
            ->where('user_id', $user->getAuthIdentifier())
            ->update(['current_session_id' => session()->getId()]);
    }

    // Shown as a toast on the page the user lands on.
    session()->flash('welcome', 'Welcome, '.trim((string) ($user->first_name ?: $user->username)));

    if ($this->remember) {
        // Remember the username (never the password) on this browser for 30 days.
        Cookie::queue('remembered_username', $this->username, 60 * 24 * 30);
    } else {
        Cookie::queue(Cookie::forget('remembered_username'));
    }

    $routeName = match ($user->role?->role_name) {
        'Admin' => 'admin.dashboard',
        'Cashier' => 'cashier.dashboard',
        'Maintenance Staff' => 'maintenance.dashboard',
        'Security Guard' => 'security.entrance-slips.create',
        default => 'dashboard',
    };

    return redirect()->route($routeName);
};

?>

<div class="w-full max-w-md">
    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mb-6 text-center">
            <img src="/images/olaer/logo.png"
                alt="Olaer Spring Resort Logo"
                class="mx-auto mb-4 aspect-square w-[clamp(4rem,18vw,8rem)] rounded-full object-cover"
            />
            <h1 class="text-2xl font-bold tracking-tight">Olaer Spring Resort</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Booking Management with Billing System</p>
        </div>

        @if (session('notice'))
            <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200" role="status">
                {{ session('notice') }}
            </div>
        @endif

        <form wire:submit="login" novalidate class="space-y-5">
            {{-- Empty fields only turn the box red; real problems (wrong password, inactive account) still show text. --}}
            @php($usernameError = filled($username) ? $errors->first('username') : '')

            <flux:field class="relative">
                <flux:label>Username</flux:label>
                <flux:input wire:model="username" name="username" autocomplete="username" autofocus :invalid="$errors->has('username')" />
                @if ($usernameError !== '')
                    <p class="field-error text-sm font-medium text-red-500 dark:text-red-400">{{ $usernameError }}</p>
                @endif
            </flux:field>

            <flux:field class="relative">
                <flux:label>Password</flux:label>
                <flux:input wire:model="password" name="password" type="password" autocomplete="current-password" viewable :invalid="$errors->has('password')" />
            </flux:field>

            <div class="flex items-center justify-between gap-4">
                <flux:checkbox wire:model="remember" label="Remember me" />

                <a href="{{ route('password.request') }}" wire:navigate
                    class="text-sm font-medium text-zinc-700 hover:underline dark:text-zinc-300">
                    Forgot password?
                </a>
            </div>

            <flux:button type="submit" variant="primary" class="w-full">
                Login
            </flux:button>
        </form>
    </div>
</div>
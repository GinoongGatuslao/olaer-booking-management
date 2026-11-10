<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * One device per account, newest login wins.
 *
 * The login page stores the session id of the newest login on the user. Any other
 * session of the same account no longer matches and is signed out on its next request.
 */
class EnforceSingleSession
{
    /** Role names that may stay signed in on several devices (for example a shared kiosk). */
    private const EXEMPT_ROLES = [];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $request->hasSession()) {
            return $next($request);
        }

        // The column is missing until `php artisan migrate` has run: skip instead of failing.
        if (! array_key_exists('current_session_id', $user->getAttributes())) {
            return $next($request);
        }

        if (self::EXEMPT_ROLES !== [] && in_array($user->role?->role_name, self::EXEMPT_ROLES, true)) {
            return $next($request);
        }

        $sessionId = $request->session()->getId();
        $activeSessionId = $user->current_session_id;

        if ($activeSessionId === null) {
            // A session that was open before this feature existed becomes the active one.
            DB::table('tbl_user')
                ->where('user_id', $user->getAuthIdentifier())
                ->whereNull('current_session_id')
                ->update(['current_session_id' => $sessionId]);

            return $next($request);
        }

        if (hash_equals((string) $activeSessionId, $sessionId)) {
            return $next($request);
        }

        // The account signed in somewhere else since. End this session without calling
        // logout(): that would also reset the remember token the newest device relies on.
        $guard = Auth::guard('web');
        $guard->forgetUser();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Cookie::queue(Cookie::forget($guard->getRecallerName()));

        $message = 'You were signed out because this account was signed in on another device.';
        $request->session()->flash('notice', $message);

        // Livewire requests (polling, button clicks) cannot follow a redirect.
        if ($request->headers->has('X-Livewire') || $request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login');
    }
}
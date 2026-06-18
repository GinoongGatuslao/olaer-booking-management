<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $userRole = strtolower(trim($user->role?->role_name ?? ''));

        $allowedRoles = collect($roles)
            ->map(fn (string $role) => strtolower(trim($role)))
            ->all();

        if (! in_array($userRole, $allowedRoles, true)) {
            abort(403, 'You are not allowed to access this page.');
        }

        return $next($request);
    }
}

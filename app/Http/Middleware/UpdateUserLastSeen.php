<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class UpdateUserLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $this->shouldUpdate($user->last_seen_at)) {
            $seenAt = now();

            DB::table('tbl_user')
                ->where('user_id', $user->getAuthIdentifier())
                ->update(['last_seen_at' => $seenAt]);

            // Keep the authenticated model in sync for the current request.
            $user->setAttribute('last_seen_at', $seenAt);
        }

        return $next($request);
    }

    private function shouldUpdate(mixed $lastSeenAt): bool
    {
        if ($lastSeenAt === null) {
            return true;
        }

        return Carbon::parse($lastSeenAt)->lt(now()->subMinute());
    }
}

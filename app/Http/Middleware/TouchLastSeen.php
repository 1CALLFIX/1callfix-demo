<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records when a signed-in user was last active, so admin people lists can show who is online.
 * Runs after the response (terminate), so route auth (web or Sanctum) has resolved the user and the request
 * pays nothing. Writes at most once a minute per user and leaves updated_at alone.
 */
class TouchLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = Auth::user();
        if (! $user || ($user->last_seen_at && $user->last_seen_at->gt(now()->subMinute()))) {
            return;
        }

        try {
            DB::table('users')->where('id', $user->getKey())->update(['last_seen_at' => now()]);
        } catch (\Throwable) {
            // Presence is a convenience; never let it break a request.
        }
    }
}

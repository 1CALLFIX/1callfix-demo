<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REF 1CF-LAUNCH-004 — closes the existing-session gap on the customer and
 * provider `web` guards that LAUNCH-003 already closed for the admin panel
 * (EnsureHasAdminAccess). Direct mirror of that middleware, one level up:
 * Customer\Auth\Login::login() and Provider\Auth\Login::login() both
 * refuse a suspended account AT login, but that check only runs once, at
 * the moment of signing in. Applied to every authenticated customer/
 * provider route group, this re-runs the same check on every subsequent
 * request, so an account suspended WHILE its holder is still logged in is
 * blocked on their very next request too — not just their next login.
 *
 * One shared class for both guards rather than two near-duplicate ones:
 * customer and provider share the same `web` session guard and the same
 * users.status column; nothing here needs to know which one it's talking
 * to. Deliberately does NOT log the user out or invalidate the session
 * itself — same posture as EnsureHasAdminAccess, which only aborts the
 * individual request. A real logout still requires the explicit
 * customer.logout/provider.logout route, exactly as before this fix.
 */
class EnsureAccountNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(
            $user && $user->status === 'suspended',
            403,
            'This account has been suspended. Contact support for help.'
        );

        return $next($request);
    }
}

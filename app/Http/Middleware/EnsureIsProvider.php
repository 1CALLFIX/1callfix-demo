<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHASE PW1 §2.1 — front door to the /provider web area. Direct mirror of
 * EnsureHasAdminAccess: it answers only "is this authenticated user a
 * service partner at all", i.e. does a `providers` row hang off their
 * `users` row. It does NOT gate on KYC / is_active / online — those are
 * shown and explained by the dashboard eligibility panel (§3.3), not
 * blocked here, so a provider mid-KYC can still sign in and see why they
 * have no work yet.
 *
 * The role column is not consulted: `users.role` is a coarse actor tag and
 * a real person can be both a customer and a partner. Profile existence is
 * the honest test, and it is exactly what DispatchController /
 * WorkerJobController already use on the API side.
 */
class EnsureIsProvider
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user && $user->providerProfile()->exists(),
            403,
            'This area is for 1CallFix service partners.'
        );

        return $next($request);
    }
}

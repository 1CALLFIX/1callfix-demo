<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Front door to the /admin panel. Replaces the old EnsureSuperAdmin gate
 * (which is now obsolete now that role_assignments exist — kept as a file
 * only for its history, no longer referenced by any route) now that
 * non-super_admin actors (Country/City/Zone Admin, Franchise Owner,
 * Operator, Support) are real, RBAC-driven roles: anyone holding at least
 * one role_assignment gets into the panel shell. WHICH screens they can
 * see and WHICH actions they can actually perform inside it are the
 * fine-grained AuthorizationService::can() checks already wired into
 * individual Livewire actions (Bookings\Show::cancel(), Franchises\Manage::
 * save(), etc.) — this middleware only answers "are they an admin-type
 * actor at all", not "can they do X".
 */
class EnsureHasAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $isAdminActor = $user && ($user->role === 'super_admin' || $user->roleAssignments()->exists());

        abort_unless($isAdminActor, 403, 'You do not have access to the admin panel.');

        return $next($request);
    }
}

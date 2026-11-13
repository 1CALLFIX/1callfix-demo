<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the organiser portal (`/organiser/*`, Events E1): the signed-in user's provider account owns an event
 * organiser. One still waiting for approval gets in (it builds its first event before approval); whether tickets can
 * be sold is the organiser's and the event's own approval, not enforced here.
 */
class EnsureIsOrganiser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->holdsPartnerRole('events'), 403, 'This area is for 1CallFix event organisers.');

        return $next($request);
    }
}

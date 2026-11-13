<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the hall portal (`/venue/*`, Function halls R1): the signed-in user's provider account owns at least one
 * hall. A hall still in draft or review gets in (the owner finishes it before approval).
 */
class EnsureIsHallOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->holdsPartnerRole('halls'), 403, 'This area is for 1CallFix function hall owners.');

        return $next($request);
    }
}

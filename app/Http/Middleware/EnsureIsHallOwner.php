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
        // One Rental portal (2026-11-14): hall owners and property owners.
        $user = $request->user();
        abort_unless($user !== null && ($user->holdsPartnerRole('halls') || $user->holdsPartnerRole('homes')), 403, 'This area is for 1CallFix rental partners.');

        return $next($request);
    }
}

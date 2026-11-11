<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the hotel portal (`/hotel/*`, Hotels H1): the signed-in user's provider account owns a hotel. A hotel still
 * waiting for approval gets in (it adds rooms, prices and photos before it opens); whether customers can book is the
 * hotel's own approval / active state, shown on the dashboard, not enforced here.
 */
class EnsureIsHotel
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->holdsPartnerRole('hotel'), 403, 'This area is for 1CallFix hotels.');

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the seller portal (`/seller/*`, E-commerce Phase 1): the signed-in user's provider account owns a shop.
 * A shop still waiting for approval gets in (the seller adds products before it opens); whether customers can buy is
 * the shop's own approval / active state, shown on the dashboard, not enforced here.
 */
class EnsureIsSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->holdsPartnerRole('commerce'), 403, 'This area is for 1CallFix sellers.');

        return $next($request);
    }
}

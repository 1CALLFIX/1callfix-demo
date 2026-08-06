<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() || $request->user()->role !== 'super_admin') {
            abort(403, 'You do not have access to the admin panel.');
        }

        return $next($request);
    }
}

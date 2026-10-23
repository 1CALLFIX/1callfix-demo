<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the rider web area (`/rider/*`): the signed-in user must have an active FieldWorker profile holding
 * the `parcel_rider` capability. Whether parcel is switched on for the rider's franchise is shown on the
 * dashboard (offers simply stop), not enforced here, so a rider can always finish a job and see earnings.
 */
class EnsureIsParcelRider
{
    public function handle(Request $request, Closure $next): Response
    {
        $worker = $request->user()?->fieldWorkerProfile;

        abort_unless(
            $worker && $worker->is_active && $worker->hasCapability('parcel_rider'),
            403,
            'This area is for 1CallFix parcel riders.'
        );

        return $next($request);
    }
}

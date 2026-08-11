<?php

namespace App\Http\Middleware;

use App\Models\Booking;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real consumer for Settings > General/System's Maintenance Mode toggle.
 * Applied to the booking-operation API routes (accept/complete/pay) in
 * routes/api.php — the Razorpay webhook is deliberately excluded, since
 * that's an external service confirming something already in flight, not
 * a new operational action to pause.
 *
 * Scope-aware like every other real setting: if the route carries a
 * {booking}, its franchise_id/zone_id feed the same resolver every other
 * tab uses, so a franchise- or zone-level maintenance override (set from
 * the Settings screen's scope picker) actually gates only that scope, not
 * the whole platform. Falls back to the global value when there's no
 * booking in the route or no override at its scope.
 */
class EnsureNotInMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        $scope = [];

        $bookingId = $request->route('booking');
        if ($bookingId) {
            $booking = Booking::select('franchise_id', 'zone_id')->find($bookingId);
            if ($booking) {
                $scope = ['franchise_id' => $booking->franchise_id, 'zone_id' => $booking->zone_id];
            }
        }

        if (Setting::get('system.maintenance_mode', '0', $scope) === '1') {
            return response()->json([
                'message' => 'This service is temporarily unavailable for maintenance. Please try again shortly.',
            ], 503);
        }

        return $next($request);
    }
}

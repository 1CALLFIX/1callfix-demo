<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Zone;
use App\Services\DispatchService;
use Illuminate\Http\Request;

/**
 * The customer-facing counterpart to internal dispatch — "which providers
 * are near me for this service, in the configured ranking order". No
 * dispatch_attempts row is created here; this is a read-only browse.
 */
class ProviderDiscoveryController extends Controller
{
    /**
     * GET /api/providers/nearby?service_id=&zone_id=&lat=&lng=
     */
    public function nearby(Request $request, DispatchService $dispatchService)
    {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'zone_id' => ['required', 'integer', 'exists:zones,id'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $service = Service::findOrFail($validated['service_id']);
        $zone = Zone::findOrFail($validated['zone_id']);

        $candidates = $dispatchService->nearbyForService(
            $service,
            $zone,
            (float) $validated['lat'],
            (float) $validated['lng'],
        );

        return response()->json([
            'providers' => $candidates->map(fn ($c) => [
                'provider_id' => $c['provider']->id,
                'name' => $c['provider']->user?->name,
                'rating_avg' => (float) $c['provider']->rating_avg,
                'jobs_completed' => $c['provider']->jobs_completed,
                'distance_km' => $c['distance_km'],
                'ranking_score' => $c['ranking_score'] ?? null,
            ])->values(),
        ]);
    }
}

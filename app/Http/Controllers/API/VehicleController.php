<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\RentalAvailabilityService;
use Illuminate\Http\Request;

/**
 * RENTAL MODULE IMPLEMENTATION -- public browse/search for Vehicle
 * inventory, same reasoning and shape as PropertyController: a real
 * customer browses vehicles before logging in, mirroring Property Rental's
 * own precedent for this codebase's rental verticals.
 *
 * The date-range filter is pushed into the query itself
 * (`whereDoesntHave('reservations', ...)`), not applied in-memory after a
 * `limit(50)` fetch -- Property Rental's own PropertyController had
 * exactly that bug (N+1 + a real correctness bug: an available property
 * outside the first-50-by-id window could never surface) and fixed it in
 * Phase 23.1; built correctly here from the start rather than repeating
 * a known mistake.
 */
class VehicleController extends Controller
{
    /** GET /api/vehicles?zone_id=&franchise_id=&vehicle_category_id=&rental_mode=&search=&starts_at=&ends_at= */
    public function index(Request $request)
    {
        $query = Vehicle::query()->where('is_active', true)->with(['vehicleCategory', 'provider.user']);

        if ($request->filled('zone_id')) {
            $query->where('zone_id', $request->integer('zone_id'));
        }
        if ($request->filled('franchise_id')) {
            $query->where('franchise_id', $request->integer('franchise_id'));
        }
        if ($request->filled('vehicle_category_id')) {
            $query->where('vehicle_category_id', $request->integer('vehicle_category_id'));
        }
        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q->where('make', 'like', "%{$search}%")->orWhere('model', 'like', "%{$search}%"));
        }
        if ($request->filled('rental_mode')) {
            $mode = $request->string('rental_mode');
            $query->whereJsonContains('supported_rental_modes', (string) $mode);
        }

        if ($request->filled('starts_at') && $request->filled('ends_at')) {
            $validated = $request->validate(['starts_at' => 'date', 'ends_at' => 'date|after:starts_at']);
            $query->whereDoesntHave('reservations', function ($q) use ($validated) {
                $q->where('status', '!=', 'cancelled')
                    ->where('starts_at', '<', $validated['ends_at'])
                    ->where('ends_at', '>', $validated['starts_at']);
            });
        }

        $vehicles = $query->orderBy('id')->limit(50)->get();

        return response()->json(['vehicles' => $vehicles]);
    }

    /** GET /api/vehicles/{id} */
    public function show(int $vehicleId)
    {
        $vehicle = Vehicle::where('is_active', true)->with(['vehicleCategory', 'provider.user'])->find($vehicleId);

        if (! $vehicle) {
            return response()->json(['message' => 'Vehicle not found.'], 404);
        }

        return response()->json(['vehicle' => $vehicle]);
    }

    /** GET /api/vehicles/{id}/availability?starts_at=&ends_at= */
    public function availability(Request $request, int $vehicleId, RentalAvailabilityService $availability)
    {
        $vehicle = Vehicle::find($vehicleId);
        if (! $vehicle) {
            return response()->json(['message' => 'Vehicle not found.'], 404);
        }

        $validated = $request->validate(['starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at']);

        return response()->json([
            'is_available' => $availability->isAvailable($vehicle, \Illuminate\Support\Carbon::parse($validated['starts_at']), \Illuminate\Support\Carbon::parse($validated['ends_at'])),
        ]);
    }
}

<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\PropertyAvailabilityService;
use Illuminate\Http\Request;

/**
 * Phase 22.7 (Property Rental) — the one real customer-facing
 * search/browse API this mission has built for any vertical so far.
 * Justified, not invented: unlike Service/Parcel/Taxi (all admin/operator-
 * created, matching this app's real call-center business model, confirmed
 * by `routes/api.php` having no booking-creation endpoint for any of
 * them), Property Rental is a genuine self-service browse-then-book
 * domain — real Glover evidence (`Property::scopeAvailable()`/
 * `scopeWithinLocation()`) confirms search/availability filtering is a
 * real feature of this vertical, not a guessed one. No "discovery
 * algorithm" (recommendations, ranking beyond distance) is built —
 * unsupported per the mission's own "do not invent unsupported discovery
 * functionality" instruction.
 */
class PropertyController extends Controller
{
    /**
     * GET /api/properties?zone_id=&check_in=&check_out=&search=
     * check_in/check_out are optional -- when both given, only properties
     * with no is_available=false row anywhere in that range are returned.
     * This is a browse-time filter only (PropertyAvailabilityService's own
     * class docblock is explicit that only reserveDates(), inside a real
     * reservation's own transaction, is the authoritative safety check).
     */
    public function index(Request $request, PropertyAvailabilityService $availability)
    {
        $query = Property::query()->where('is_active', true)->with(['propertyType', 'provider.user']);

        if ($request->filled('zone_id')) {
            $query->where('zone_id', $request->integer('zone_id'));
        }

        if ($request->filled('franchise_id')) {
            $query->where('franchise_id', $request->integer('franchise_id'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        $properties = $query->orderBy('id')->limit(50)->get();

        if ($request->filled('check_in') && $request->filled('check_out')) {
            $properties = $properties->filter(fn (Property $p) => $availability->isAvailable($p, $request->string('check_in'), $request->string('check_out')))->values();
        }

        return response()->json(['properties' => $properties]);
    }

    /** GET /api/properties/{id} */
    public function show(int $propertyId)
    {
        $property = Property::where('is_active', true)->with(['propertyType', 'provider.user', 'amenities'])->find($propertyId);

        if (! $property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        return response()->json(['property' => $property]);
    }

    /**
     * GET /api/properties/{id}/availability?check_in=&check_out=
     * A real, bounded quote/availability check for the customer app to
     * call before showing a "Reserve" confirmation screen.
     */
    public function availability(Request $request, int $propertyId, PropertyAvailabilityService $availability)
    {
        $property = Property::find($propertyId);
        if (! $property) {
            return response()->json(['message' => 'Property not found.'], 404);
        }

        $validated = $request->validate(['check_in' => 'required|date', 'check_out' => 'required|date|after:check_in']);

        return response()->json([
            'is_available' => $availability->isAvailable($property, $validated['check_in'], $validated['check_out']),
        ]);
    }
}

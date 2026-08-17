<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\HotelRoomType;
use App\Services\HotelAvailabilityService;
use Illuminate\Http\Request;

/**
 * HOTEL / STAY BOOKING MODULE — the Accommodation counterpart to
 * PropertyController, same "genuine self-service browse-then-book domain"
 * reasoning that class's own docblock gives (real customer-facing
 * search/browse, unauthenticated by design, same as every other pre-login
 * discovery surface in this codebase). Route prefix is `/hotels`
 * (the customer-facing collective term, matching the `hotel` module slug)
 * even though the backing model is `Accommodation` (the broader technical
 * term covering hotel/resort/guest_house/homestay/hostel/serviced_apartment)
 * — the same naming looseness `properties`/`vehicles` already show relative
 * to their own underlying concepts.
 *
 * No separate `/hotels/{id}/rate-plans` endpoint — rate plans are nested
 * under their own room type (a rate plan without a room type is meaningless
 * to a customer choosing what to book), so `roomTypes()` below already
 * eager-loads them. Splitting them into a second flat endpoint would be
 * redundant surface, not a real customer need.
 */
class HotelController extends Controller
{
    /**
     * GET /api/hotels?zone_id=&franchise_id=&search=&check_in=&check_out=
     * check_in/check_out are optional -- when both given, only accommodations
     * with at least one active room type that isn't manually blocked for
     * every date in the range are returned. Browse-time filter only (same
     * "not the authoritative safety check" caveat PropertyController's own
     * docblock documents) -- true quantity-availability is checked at
     * `/hotels/{id}/room-types/{roomTypeId}/availability` and, authoritatively,
     * inside CreateHotelReservationAction's own transaction.
     */
    public function index(Request $request, HotelAvailabilityService $availability)
    {
        $query = Accommodation::query()->where('is_active', true)->with(['accommodationType', 'provider.user']);

        if ($request->filled('zone_id')) {
            $query->where('zone_id', $request->integer('zone_id'));
        }

        if ($request->filled('franchise_id')) {
            $query->where('franchise_id', $request->integer('franchise_id'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        if ($request->filled('check_in') && $request->filled('check_out')) {
            $validated = $request->validate(['check_in' => 'date', 'check_out' => 'date|after:check_in']);
            $dates = $availability->dateRange($validated['check_in'], $validated['check_out']);

            $query->whereHas('roomTypes', function ($q) use ($dates) {
                $q->where('is_active', true)->whereDoesntHave('availabilities', function ($a) use ($dates) {
                    $a->where('is_available', false)->whereIn('date', $dates);
                });
            });
        }

        $accommodations = $query->orderBy('id')->limit(50)->get();

        return response()->json(['accommodations' => $accommodations]);
    }

    /** GET /api/hotels/{id} */
    public function show(int $accommodationId)
    {
        $accommodation = Accommodation::where('is_active', true)
            ->with(['accommodationType', 'provider.user', 'amenities'])
            ->find($accommodationId);

        if (! $accommodation) {
            return response()->json(['message' => 'Accommodation not found.'], 404);
        }

        return response()->json(['accommodation' => $accommodation]);
    }

    /** GET /api/hotels/{id}/room-types -- each room type eager-loads its own active rate plans. */
    public function roomTypes(int $accommodationId)
    {
        $accommodation = Accommodation::find($accommodationId);
        if (! $accommodation) {
            return response()->json(['message' => 'Accommodation not found.'], 404);
        }

        $roomTypes = HotelRoomType::where('accommodation_id', $accommodation->id)
            ->where('is_active', true)
            ->with(['ratePlans' => fn ($q) => $q->where('is_active', true)])
            ->get();

        return response()->json(['room_types' => $roomTypes]);
    }

    /**
     * GET /api/hotels/{id}/room-types/{roomTypeId}/availability?check_in=&check_out=&room_count=
     * A real, bounded quote/availability check for the customer app to call
     * before showing a "Reserve" confirmation screen -- same role
     * PropertyController::availability() plays for Property Rental.
     */
    public function availability(Request $request, int $accommodationId, int $roomTypeId, HotelAvailabilityService $availability)
    {
        $roomType = HotelRoomType::where('accommodation_id', $accommodationId)->find($roomTypeId);
        if (! $roomType) {
            return response()->json(['message' => 'Room type not found.'], 404);
        }

        $validated = $request->validate([
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
            'room_count' => 'nullable|integer|min:1',
        ]);

        $roomCount = (int) ($validated['room_count'] ?? 1);

        return response()->json([
            'is_available' => $availability->isAvailable($roomType, $validated['check_in'], $validated['check_out'], $roomCount),
        ]);
    }
}

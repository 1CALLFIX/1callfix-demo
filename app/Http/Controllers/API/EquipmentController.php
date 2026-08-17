<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EquipmentItem;
use App\Services\RentalAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * RENTAL MODULE IMPLEMENTATION -- public browse/search for Equipment
 * inventory, the Equipment counterpart to VehicleController/
 * PropertyController. See VehicleController's own docblock for why the
 * date-range filter is pushed into the query, not applied in-memory.
 */
class EquipmentController extends Controller
{
    /** GET /api/equipment?zone_id=&franchise_id=&equipment_category_id=&search=&starts_at=&ends_at= */
    public function index(Request $request)
    {
        $query = EquipmentItem::query()->where('is_active', true)->with(['equipmentCategory', 'provider.user']);

        if ($request->filled('zone_id')) {
            $query->where('zone_id', $request->integer('zone_id'));
        }
        if ($request->filled('franchise_id')) {
            $query->where('franchise_id', $request->integer('franchise_id'));
        }
        if ($request->filled('equipment_category_id')) {
            $query->where('equipment_category_id', $request->integer('equipment_category_id'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        if ($request->filled('starts_at') && $request->filled('ends_at')) {
            $validated = $request->validate(['starts_at' => 'date', 'ends_at' => 'date|after:starts_at']);
            $query->whereDoesntHave('reservations', function ($q) use ($validated) {
                $q->where('status', '!=', 'cancelled')
                    ->where('starts_at', '<', $validated['ends_at'])
                    ->where('ends_at', '>', $validated['starts_at']);
            });
        }

        $items = $query->orderBy('id')->limit(50)->get();

        return response()->json(['equipment' => $items]);
    }

    /** GET /api/equipment/{id} */
    public function show(int $itemId)
    {
        $item = EquipmentItem::where('is_active', true)->with(['equipmentCategory', 'provider.user'])->find($itemId);

        if (! $item) {
            return response()->json(['message' => 'Equipment item not found.'], 404);
        }

        return response()->json(['equipment' => $item]);
    }

    /** GET /api/equipment/{id}/availability?starts_at=&ends_at= */
    public function availability(Request $request, int $itemId, RentalAvailabilityService $availability)
    {
        $item = EquipmentItem::find($itemId);
        if (! $item) {
            return response()->json(['message' => 'Equipment item not found.'], 404);
        }

        $validated = $request->validate(['starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at']);

        return response()->json([
            'is_available' => $availability->isAvailable($item, Carbon::parse($validated['starts_at']), Carbon::parse($validated['ends_at'])),
        ]);
    }
}

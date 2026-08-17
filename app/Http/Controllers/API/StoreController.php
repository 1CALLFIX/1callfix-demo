<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;

/**
 * Phase 24 (Marketplace Foundation) — public browse API, same reasoning
 * PropertyController's own docblock gives: a real customer browses stores
 * before logging in, matching real 6amMart evidence (an unauthenticated
 * store/item listing is the actual product shape) rather than this
 * codebase's admin/operator-created default (Service/Parcel/Taxi).
 */
class StoreController extends Controller
{
    /** GET /api/stores?module=&zone_id=&search= */
    public function index(Request $request)
    {
        $query = Store::query()->where('is_active', true)->with(['provider.user']);

        if ($request->filled('module')) {
            $query->where('module', $request->string('module'));
        }
        if ($request->filled('zone_id')) {
            $query->where('zone_id', $request->integer('zone_id'));
        }
        if ($request->filled('franchise_id')) {
            $query->where('franchise_id', $request->integer('franchise_id'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }

        $stores = $query->orderBy('id')->limit(50)->get();

        return response()->json(['stores' => $stores]);
    }

    /** GET /api/stores/{id} */
    public function show(int $storeId)
    {
        $store = Store::where('is_active', true)->with(['provider.user'])->find($storeId);

        if (! $store) {
            return response()->json(['message' => 'Store not found.'], 404);
        }

        return response()->json(['store' => $store]);
    }
}

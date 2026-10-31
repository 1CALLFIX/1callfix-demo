<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ParcelPack;
use App\Models\ParcelPackPurchase;
use App\Models\Zone;
use App\Services\Parcel\ParcelPackService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;

/**
 * Parcel Stage 2 — prepaid parcel packs for the customer app. Same rules as the web page (ParcelPackService):
 * bought online (Razorpay; the capture webhook activates it) or from the wallet, never cash.
 */
class ParcelPackController extends Controller
{
    /** GET /api/parcel-packs?zone_id= — packs on sale there, and the customer's own active packs. */
    public function index(Request $request, ParcelPackService $packs)
    {
        $franchiseId = $request->filled('zone_id') ? Zone::whereKey($request->integer('zone_id'))->value('franchise_id') : null;

        return ApiResponse::success([
            'on_sale' => $packs->onSale($franchiseId)->map(fn (ParcelPack $p) => [
                'id' => $p->id, 'name' => $p->name, 'parcel_count' => $p->parcel_count, 'max_km' => (float) $p->max_km,
                'price' => (float) $p->price, 'validity_days' => $p->validity_days,
            ])->values(),
            'mine' => ParcelPackPurchase::where('user_id', $request->user()->id)->where('status', 'active')->latest('id')->get()->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'parcels_total' => $p->parcels_total, 'parcels_left' => $p->remaining(),
                'max_km' => (float) $p->max_km, 'expires_at' => $p->expires_at?->toIso8601String(), 'usable' => $p->isUsable(),
            ])->values(),
        ]);
    }

    /** POST /api/parcel-packs/{pack}/buy {method: online|wallet} — online returns the Razorpay checkout payload. */
    public function buy(Request $request, int $packId, ParcelPackService $packs)
    {
        $validated = $request->validate(['method' => ['required', 'in:online,wallet']]);
        $pack = ParcelPack::where('is_active', true)->find($packId);
        if (! $pack) {
            return ApiResponse::error('This pack is not on sale.', 404);
        }

        try {
            return ApiResponse::success($packs->buy($request->user(), $pack, $validated['method']), null, 201);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }
    }
}

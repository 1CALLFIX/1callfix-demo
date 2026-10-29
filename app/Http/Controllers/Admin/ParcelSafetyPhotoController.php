<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ParcelSafetyPhoto;
use Illuminate\Support\Facades\Storage;

/**
 * Parcel pickup safety (2026-10-29): the only way a rider's safety photo is opened — streamed from the PRIVATE disk
 * after a parcel_orders.view check at the order's own scope. 404 (not 403) when not allowed, the same
 * information-hiding convention as KycDocumentController.
 */
class ParcelSafetyPhotoController extends Controller
{
    public function show(int $photoId)
    {
        $photo = ParcelSafetyPhoto::with('parcelOrder.franchise')->find($photoId);
        abort_if(! $photo || ! $photo->parcelOrder, 404);

        $order = $photo->parcelOrder;
        $allowed = auth()->user()->hasPermission('parcel_orders.view', array_filter([
            'zone_id' => $order->zone_id, 'franchise_id' => $order->franchise_id,
            'city_id' => $order->franchise?->city_id, 'country_id' => $order->franchise?->country_id,
        ]));
        abort_if(! $allowed, 404);
        abort_if(! Storage::disk('local')->exists($photo->disk_path), 404);

        return Storage::disk('local')->response($photo->disk_path, null, ['Content-Disposition' => 'inline']);
    }
}

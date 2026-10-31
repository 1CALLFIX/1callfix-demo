<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ParcelProtectionClaim;
use App\Services\Parcel\ParcelProtectionClaimService;
use Illuminate\Support\Facades\Storage;

/**
 * Parcel Stage 2 — the only way a claim photo is opened: streamed from the PRIVATE disk to a user who can see that
 * claim in the Parcel Claims queue. 404 (not 403) when not allowed, same convention as ParcelSafetyPhotoController.
 */
class ParcelClaimPhotoController extends Controller
{
    public function show(int $claimId, int $index, ParcelProtectionClaimService $service)
    {
        $claim = $service->visibleTo(ParcelProtectionClaim::query(), auth()->user())->find($claimId);
        abort_if(! $claim || ! $service->canEnter(auth()->user()), 404);

        $path = ($claim->photos ?? [])[$index] ?? null;
        abort_if(! $path || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Content-Disposition' => 'inline']);
    }
}

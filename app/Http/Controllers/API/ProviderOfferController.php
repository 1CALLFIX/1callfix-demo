<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\DispatchAttempt;
use App\Models\Setting;
use Illuminate\Http\Request;

class ProviderOfferController extends Controller
{
    /**
     * GET /api/provider/offers
     * Called by the 1CallFix Partner app to list this provider's live job
     * offers — the read-only counterpart to DispatchController::accept().
     * Same provider-resolution and 403 pattern as that controller; no new
     * dispatch state, no expiry write. ServiceMatchingJob is still the only
     * thing that ever flips a 'notified' attempt to 'timeout'.
     */
    public function index(Request $request)
    {
        $provider = $request->user()->providerProfile;

        if (!$provider) {
            return response()->json(['message' => 'Only provider accounts can view job offers.'], 403);
        }

        $offerTimeoutSeconds = (int) Setting::get('dispatch.offer_timeout_seconds', 25);

        $offers = DispatchAttempt::where('provider_id', $provider->id)
            ->where('status', 'notified')
            // ServiceMatchingJob only flips notified -> timeout when its own
            // delayed re-dispatch job runs, so a row whose window has
            // already elapsed can still read status='notified' here. Same
            // notified_at guard Livewire\Provider\Jobs\Index::render() uses
            // for its own pre-accept offer listing, so this endpoint can't
            // present a stale offer as live.
            ->where('notified_at', '>=', now()->subSeconds($offerTimeoutSeconds))
            ->whereHas('booking', fn ($q) => $q->where('status', 'searching_provider'))
            ->with(['booking.service', 'booking.address'])
            ->orderByDesc('notified_at')
            ->get();

        return response()->json([
            'offers' => $offers->map(fn (DispatchAttempt $attempt) => [
                'booking_id' => $attempt->booking_id,
                'dispatch_attempt_id' => $attempt->id,
                'booking_code' => $attempt->booking->code,
                'service_name' => $attempt->booking->service?->name,
                // Deliberately coarse: the existing pre-accept provider
                // portal (resources/views/livewire/provider/jobs/index.blade.php)
                // shows no address at all before acceptance — only service
                // name + distance. City-level granularity here is a
                // reasonable middle ground for the app's accept/decline
                // decision without exposing address_line/landmark/pincode/
                // lat/lng pre-commitment. See UNKNOWN ITEMS in the task
                // report: the exact "pickup summary" shape is a product
                // decision, not one this task can safely invent further.
                'address_summary' => $attempt->booking->address?->city,
                'price_quoted' => $attempt->booking->price_quoted,
                'distance_km' => $attempt->distance_km,
                'notified_at' => optional($attempt->notified_at)->toIso8601String(),
                'offer_expires_at' => optional($attempt->notified_at)
                    ->copy()
                    ->addSeconds($offerTimeoutSeconds)
                    ->toIso8601String(),
            ])->values(),
        ]);
    }
}

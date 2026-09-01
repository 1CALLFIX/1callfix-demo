<?php

namespace App\Actions;

use App\Models\Provider;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * PHASE PW1 §3.1 — the one new business-logic class in the Provider Web P1
 * build. A provider flips their own availability from the web dashboard,
 * optionally handing over a fresh browser geolocation fix in the same call.
 *
 * Why an Action (and the only one): going online/offline is the single
 * provider-web operation that writes a dispatch-relevant column
 * (`providers.is_online`, plus the `current_lat`/`current_lng` pair
 * `DispatchService::eligibleQuery()` hard-filters on), needs a row lock so a
 * double-tap or a heartbeat racing a manual toggle can't interleave, and
 * carries an audit consequence. Everything else on the provider web is a
 * read, or a call into an Action that already exists
 * (AcceptBookingAction / StartBookingAction / CompleteBookingAction).
 *
 * Contract:
 *   - `$online = true`  with a usable fix  -> set is_online, current_lat,
 *                                             current_lng, stamp
 *                                             location_updated_at.
 *   - `$online = true`  with no / partial / out-of-range coords -> set
 *                                             is_online ONLY. The dashboard
 *                                             eligibility panel then renders
 *                                             the "online but no location
 *                                             fix — you will not receive
 *                                             jobs" state (§3.3). Going
 *                                             online never fails just
 *                                             because geolocation returned
 *                                             nothing usable.
 *   - `$online = false` -> set is_online = false; last known coordinates and
 *                          location_updated_at are left untouched (debug /
 *                          audit value; dispatch already ignores an offline
 *                          provider).
 *
 * Repeated online calls (the dashboard's location heartbeat) simply
 * re-stamp the fix — that is the intended freshness mechanism.
 */
class SetProviderOnlineStatusAction
{
    public function execute(Provider $provider, bool $online, ?float $lat = null, ?float $lng = null): Provider
    {
        $hasFix = $online && $this->isUsableFix($lat, $lng);

        $fresh = DB::transaction(function () use ($provider, $online, $lat, $lng, $hasFix) {
            /** @var Provider $locked */
            $locked = Provider::whereKey($provider->getKey())->lockForUpdate()->firstOrFail();

            $locked->is_online = $online;

            if ($hasFix) {
                $locked->current_lat = $lat;
                $locked->current_lng = $lng;
                $locked->location_updated_at = now();
            }

            $locked->save();

            return $locked;
        });

        ActivityLogger::logModel(
            auth()->user(),
            $fresh,
            $online ? 'Went online (provider web)' : 'Went offline (provider web)',
            $hasFix ? ['lat' => (float) $lat, 'lng' => (float) $lng] : [],
        );

        return $fresh->refresh();
    }

    /**
     * A fix is only recorded when BOTH components are present, finite, and
     * within real geographic range. A partial or garbage fix (denied
     * permission, a flaky JS bridge, NaN) is treated exactly like "no fix" —
     * silently not recorded, never an exception — so the toggle still
     * succeeds and the eligibility panel is left to explain why no jobs will
     * come.
     */
    private function isUsableFix(?float $lat, ?float $lng): bool
    {
        return $lat !== null && $lng !== null
            && is_finite($lat) && is_finite($lng)
            && $lat >= -90.0 && $lat <= 90.0
            && $lng >= -180.0 && $lng <= 180.0;
    }
}

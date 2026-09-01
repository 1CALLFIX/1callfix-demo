<?php

namespace App\Livewire\Provider\Concerns;

use App\Models\Provider;
use App\Models\Setting;

/**
 * PHASE PW1 §3.3 — "why you will / won't get jobs". Pure presentation of the
 * exact predicates DispatchService::eligibleQuery()/findCandidates() apply
 * (app/Services/DispatchService.php) — no new rule is invented here, and
 * nothing is enforced: the components only render this.
 *
 * `blocking` = a hard dispatch exclusion today. `advisory` = a warning the
 * dispatcher does not currently act on (location staleness) — surfaced so a
 * provider isn't blindsided, but it never flips `dispatchBlocked()`.
 */
trait BuildsProviderEligibility
{
    /** @return list<array{key:string,label:string,ok:bool,advisory:bool,fail:string}> */
    protected function eligibilityChecks(Provider $p): array
    {
        $staleMinutes = max(1, (int) Setting::get('provider.location_stale_after_minutes', '30'));
        $hasFix = $p->current_lat !== null && $p->current_lng !== null;
        $fixFresh = $hasFix
            && $p->location_updated_at !== null
            && $p->location_updated_at->greaterThan(now()->subMinutes($staleMinutes));

        return [
            [
                'key' => 'online', 'label' => 'Online', 'ok' => (bool) $p->is_online, 'advisory' => false,
                'fail' => "You're offline — you won't be offered any jobs.",
            ],
            [
                'key' => 'location', 'label' => 'Location fix', 'ok' => $hasFix, 'advisory' => false,
                'fail' => "You're online but we don't have your location — you will NOT receive jobs. Allow location access and go online again.",
            ],
            [
                'key' => 'location_fresh', 'label' => 'Location current', 'ok' => $fixFresh, 'advisory' => true,
                'fail' => "Your last location fix is over {$staleMinutes} min old — it may be inaccurate. Refresh it.",
            ],
            [
                'key' => 'kyc', 'label' => 'KYC approved', 'ok' => $p->kyc_status === 'approved', 'advisory' => false,
                'fail' => 'Your KYC is '.($p->kyc_status ?: 'pending').'. Jobs resume once an admin approves it.',
            ],
            [
                'key' => 'active', 'label' => 'Account active', 'ok' => (bool) $p->is_active, 'advisory' => false,
                'fail' => 'Your account is inactive. Contact support.',
            ],
            [
                'key' => 'skills', 'label' => 'Service categories set', 'ok' => ! empty($p->skills), 'advisory' => false,
                'fail' => 'No service categories on your profile yet — an admin sets these.',
            ],
            [
                'key' => 'zone', 'label' => 'Zone assigned', 'ok' => $p->zone_id !== null, 'advisory' => false,
                'fail' => 'No zone assigned — an admin sets this.',
            ],
        ];
    }

    /** @param list<array{ok:bool,advisory:bool}> $checks */
    protected function dispatchBlocked(array $checks): bool
    {
        foreach ($checks as $c) {
            if (! $c['advisory'] && ! $c['ok']) {
                return true;
            }
        }

        return false;
    }
}

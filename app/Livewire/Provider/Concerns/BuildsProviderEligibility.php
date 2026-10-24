<?php

namespace App\Livewire\Provider\Concerns;

use App\Models\Provider;
use App\Models\Setting;
use App\Services\Providers\ProviderStanding;

/**
 * PHASE PW1 §3.3 — "why you will / won't get jobs". Pure presentation of the
 * exact predicates DispatchService::eligibleQuery()/findCandidates() apply
 * (app/Services/DispatchService.php) — no new rule is invented here, and
 * nothing is enforced: the components only render this.
 *
 * `blocking` = a hard dispatch exclusion today. `advisory` = a warning the
 * dispatcher does not act on; it never flips `dispatchBlocked()`. Location
 * staleness used to be advisory, but DispatchService::eligibleQuery() has
 * enforced it since 1CF-LAUNCH-008, so it is blocking here too: the panel
 * must never say "eligible" to a provider dispatch is skipping.
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
        $money = array_values(array_filter(
            app(ProviderStanding::class)->blockers($p),
            fn ($blocker) => in_array($blocker['code'], [ProviderStanding::BELOW_FLOOR, ProviderStanding::DUES_OVER_LIMIT], true),
        ));

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
                // Only once a fix exists; with none, the check above already names the problem.
                'key' => 'location_fresh', 'label' => 'Location current', 'ok' => ! $hasFix || $fixFresh, 'advisory' => false,
                'fail' => "Your location hasn't updated for over {$staleMinutes} min — you will NOT receive jobs. Tap \"Refresh location\", and keep this page open with the screen on while you wait for jobs.",
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
            // Audit 2026-10-15 L1/L3 — the same money rule dispatch enforces (ProviderStanding).
            [
                'key' => 'wallet', 'label' => 'Wallet & dues in order', 'ok' => $money === [], 'advisory' => false,
                'fail' => implode(' ', array_column($money, 'message')).' Open Payments → Wallet & dues to pay.',
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

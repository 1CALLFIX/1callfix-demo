<?php

namespace App\Services;

use App\Models\Franchise;
use App\Models\Provider;
use App\Models\ProviderCommissionAgreement;
use App\Models\Setting;

/**
 * The platform_fee_percent that applies to a franchise/provider pair,
 * resolved through a three-tier hierarchy:
 *
 *   1. Provider negotiated agreement (provider_commission_agreements) — a
 *      row's mere existence for this provider IS the override.
 *   2. Franchise default (franchises.platform_fee_percent) — the franchise's
 *      own configured rate. NULL means "unconfigured," not "explicitly 0%"
 *      (see the 2026_09_05_001000 migration's docblock for why the column
 *      had to become nullable to make that distinction possible at all).
 *   3. Global SuperAdmin default (Setting `commission.default_platform_fee_percent`,
 *      seeded to 30 by 2026_09_05_004000) — the platform-wide fallback.
 *
 * Two deliberate design decisions, made explicit here because both affect
 * how "franchise default" and "global default" relate to each other:
 *
 * - Tier 2 reads the franchises.platform_fee_percent COLUMN directly, not
 *   Setting::get('commission.default_platform_fee_percent', ..., ['franchise_id' => ...]) —
 *   even though Setting already supports a franchise-scoped override of that
 *   exact key via the Settings screen's scope picker. Routing tier 2 through
 *   Setting instead would make the franchises.platform_fee_percent column
 *   pointless to have just made nullable and backfilled, and would create
 *   two disconnected admin surfaces (Franchises→Edit, Settings→Commission
 *   scope=Franchise) that could each silently set "this franchise's rate"
 *   with no defined precedence between them. That's a known, documented gap
 *   for a future cleanup — not solved here.
 *
 * - Tier 3 calls Setting::get() with an EMPTY scope array, deliberately not
 *   exercising Setting's own zone/franchise/city/country cascade — Setting
 *   is used here purely as a global key-value store. This keeps each tier's
 *   meaning unambiguous: tier 1 = agreement, tier 2 = franchise column,
 *   tier 3 = the one global value, nothing more layered underneath.
 *
 * $provider is nullable so the shared FieldWorker/Provider commission helper
 * (CommissionService::applyForFieldWorkerOrder()) can call this uniformly:
 * a FieldWorker earner passes null and simply never gets a tier-1 lookup
 * (agreements are Provider-scoped only), falling through to tier 2/3 exactly
 * like every other caller — no FieldWorker split-calculation behavior
 * changes, only the upstream rate input does.
 */
class ProviderCommercialRateResolver
{
    public function resolve(Franchise $franchise, ?Provider $provider): float
    {
        if ($provider) {
            $agreement = ProviderCommissionAgreement::where('provider_id', $provider->id)->first();
            if ($agreement) {
                return (float) $agreement->platform_fee_percent;
            }
        }

        if ($franchise->platform_fee_percent !== null) {
            return (float) $franchise->platform_fee_percent;
        }

        return (float) Setting::get('commission.default_platform_fee_percent', '30', []);
    }

    /**
     * Which tier resolve() would draw from, for admin-UI display only (e.g.
     * Providers\Show's "effective rate" panel) — never consulted by
     * CommissionService, which only needs the numeric rate.
     */
    public function resolvedTier(Franchise $franchise, ?Provider $provider): string
    {
        if ($provider && ProviderCommissionAgreement::where('provider_id', $provider->id)->exists()) {
            return 'agreement';
        }

        if ($franchise->platform_fee_percent !== null) {
            return 'franchise';
        }

        return 'global';
    }
}

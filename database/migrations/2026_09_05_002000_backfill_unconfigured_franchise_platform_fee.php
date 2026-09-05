<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Phase: Provider Commercial Rate Resolver — Phase 0/1
//
// This is the "re-verify against production directly, don't assume from
// local-DB evidence" step: it queries whatever database it actually runs
// against (production, once deployed there — NOT a value assumed ahead of
// time), and only ever acts on what it finds at that moment.
//
// Backfill rule: any franchise whose platform_fee_percent is exactly 0 is
// treated as unconfigured and set to NULL, so it falls through to the new
// franchise->global resolver chain instead of silently charging 0% platform
// commission forever (hard requirement: production must not stay at an
// effective 0% after this ships).
//
// `updated_at <> created_at` is logged as a caveat, not a gate: it's the only
// signal available (there is no per-field audit trail on franchise edits —
// confirmed by inspecting app/Livewire/Franchises/Manage.php, whose save()/
// update()/toggleStatus() write no ActivityLog entry), and it's a weak one —
// an admin could have bumped updated_at editing name/status/etc. without
// ever touching the commission fields. Gating the backfill on it would risk
// leaving a genuinely-unconfigured franchise at 0% just because of an
// unrelated edit, which directly violates the hard requirement. So it's
// surfaced via Log::warning for a human to review, but the backfill still
// runs based on the value alone.
//
// Only platform_fee_percent is backfilled — commission_value is out of this
// phase's scope (see the nullable migration's docblock).
return new class extends Migration
{
    public function up(): void
    {
        $candidates = DB::table('franchises')
            ->whereNull('deleted_at')
            ->where('platform_fee_percent', 0)
            ->get(['id', 'name', 'created_at', 'updated_at']);

        foreach ($candidates as $franchise) {
            if ((string) $franchise->updated_at !== (string) $franchise->created_at) {
                Log::warning('[commercial-rate-resolver] Backfilling platform_fee_percent to NULL on a franchise edited since creation — review whether that edit deliberately set the rate to 0%.', [
                    'franchise_id' => $franchise->id,
                    'franchise_name' => $franchise->name,
                    'created_at' => $franchise->created_at,
                    'updated_at' => $franchise->updated_at,
                ]);
            }
        }

        DB::table('franchises')
            ->whereNull('deleted_at')
            ->where('platform_fee_percent', 0)
            ->update(['platform_fee_percent' => null]);
    }

    public function down(): void
    {
        // Not reversible: we can't tell which NULLs were this migration's
        // doing vs. set some other way afterward. Leaving NULL alone on
        // rollback is the safe choice — reverting to a hard 0 would silently
        // reintroduce the exact bug this migration exists to fix.
    }
};

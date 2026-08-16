<?php

use App\Support\Modules as ModuleList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 22.1 (Module Activation Foundation). Every existing franchise
 * predates `module_activations` and `FranchiseObserver`'s new hook (which
 * only fires for franchises created AFTER this phase) — without this
 * backfill, every one of them would have zero activation rows, and
 * ModuleActivationService::isActive('service', ...) would fall through to
 * its documented legacy-default-on exception for every request anyway.
 * This migration makes that explicit and real (an actual row an admin can
 * see and, if they ever choose to, turn off) rather than relying silently
 * on the fallback for pre-existing franchises forever.
 *
 * Deliberately does NOT touch `franchise_modules` (the 2026_08_05 table) —
 * that table is left exactly as-is, frozen, per the mission brief's
 * "handle existing franchise_modules safely" instruction: it is real
 * historical/admin-configured data (principle #1, "never destructive-
 * migrate production data"), just no longer read by any new code from this
 * phase onward. Its other 7 boolean columns (food/parcel/taxi/grocery/
 * pharmacy/commerce/bookings) are NOT backfilled into module_activations:
 * every one of those modules is_implemented=false, so an explicit
 * activation row and an absent one are behaviorally identical under the
 * new resolver (§7's gate makes this true regardless) — backfilling them
 * would only manufacture rows with no observable effect. If a franchise's
 * franchise_modules row has a real `true` for one of those columns, that
 * historical intent is preserved, unread, in the frozen legacy table, and
 * can be manually re-applied via the new Modules admin screen the day that
 * module actually ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        $serviceModuleId = DB::table('modules')->where('code', ModuleList::SERVICE)->value('id');

        if (! $serviceModuleId) {
            return; // defensive only — the modules table migration always seeds this row
        }

        $now = now();

        DB::table('franchises')->pluck('id')->each(function ($franchiseId) use ($serviceModuleId, $now) {
            DB::table('module_activations')->insertOrIgnore([
                'module_id' => $serviceModuleId,
                'scope_type' => 'franchise',
                'scope_id' => $franchiseId,
                'is_active' => true,
                'created_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        $serviceModuleId = DB::table('modules')->where('code', ModuleList::SERVICE)->value('id');

        DB::table('module_activations')
            ->where('module_id', $serviceModuleId)
            ->where('scope_type', 'franchise')
            ->delete();
    }
};

<?php

use App\Support\Modules as ModuleList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.1 (Module Activation Foundation). `modules.is_active` (this
 * table's original column, 2026_08_11_003000) already means "registered as
 * a real platform vertical" — every row seeds true, all 9 are genuinely on
 * the roadmap (see that migration's own docblock). That is NOT the same
 * question as "does this vertical actually have a working operational
 * module behind it right now" — today, only Services does. PHASE_22_
 * PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §3 found no column anywhere
 * answering that second question, which is exactly the gap the mission
 * brief names: "unimplemented module must never appear as usable merely
 * because its key exists."
 *
 * `is_implemented` is that column. `ModuleActivationService::isActive()`
 * refuses to report a module active — at ANY scope, regardless of what a
 * `module_activations` row says — unless `is_implemented` is true here.
 * This is a hard gate, not a display flag: an admin can pre-configure
 * Parcel/Food/etc. activation rows once those modules exist in code, but
 * none of it has any customer-facing effect until this flag is flipped by
 * a real deployment of that module's operational code — not by this
 * migration, and not by the admin activation screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->boolean('is_implemented')->default(false)->after('is_active');
        });

        // Only `service` is a real, working operational module today
        // (PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §5 — every other
        // module is classified B/D). Parcel has a polymorphic dispatch
        // foundation but no operational order/pricing/catalog behavior of
        // its own, so it stays false here too until that's actually built.
        DB::table('modules')->where('code', ModuleList::SERVICE)->update(['is_implemented' => true]);
    }

    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropColumn('is_implemented');
        });
    }
};

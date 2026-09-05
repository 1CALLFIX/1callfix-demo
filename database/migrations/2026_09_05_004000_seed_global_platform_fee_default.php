<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

// Phase: Provider Commercial Rate Resolver — Phase 1
//
// `commission.default_platform_fee_percent` has been a live, working field on
// the admin Settings screen (App\Livewire\Settings\Manage::saveCommission())
// since before this phase -- it was simply never seeded, so Setting::get()
// fell through to its hardcoded '0' literal fallback. This is the actual,
// intended platform default (30%) approved for the Global -> Franchise ->
// Provider hierarchy, seeded as a real Setting row rather than left as an
// unconfigured hard-fallback literal in code.
//
// Guarded by existsAt() so this never clobbers a value an admin may already
// have saved through the Settings screen between Phase 0 and this deploy.
return new class extends Migration
{
    public function up(): void
    {
        if (! Setting::existsAt('commission.default_platform_fee_percent', 'global', null)) {
            Setting::set('commission.default_platform_fee_percent', '30', 'global', null);
        }
    }

    public function down(): void
    {
        // Deliberately a no-op: this migration only seeds a value if none
        // existed. Removing it on rollback could delete a value an admin has
        // since edited through the Settings screen, which down() has no way
        // to distinguish from the original seed.
    }
};

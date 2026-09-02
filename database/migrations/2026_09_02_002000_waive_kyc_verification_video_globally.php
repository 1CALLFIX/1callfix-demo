<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// PHASE PSR — decision D9. ReviewProviderKycAction refuses to approve any
// provider unless an APPROVED verification video exists whenever
// `kyc.require_verification_video` resolves truthy (its in-code default is
// '1'). There is still NO path anywhere that lets a provider SUBMIT a
// verification video — KycVerificationVideoService::submit() has no route,
// controller or Livewire caller (pre-existing gap, see
// PHASE_PSR_PROVIDER_SELF_REGISTRATION_DISCOVERY.md §5.3). With the
// requirement on, EVERY provider — CSV-imported, admin-created or
// self-registered — is un-approvable.
//
// D9 for v1: waive it through the setting that already exists rather than a
// new self-reg-only mechanism. The setting cascades by geography only
// (global / country / city / zone / module / franchise) with no
// per-provider or per-origin dimension, so it cannot distinguish a
// self-registered provider from a CSV one — a carve-out would need new
// code the decision explicitly rules out. This writes the GLOBAL scope
// only; a franchise that later builds or accepts videos re-enables the
// requirement for itself with a franchise-scope override from
// Admin -> Settings without touching this row.
//
// Reversible: down() removes the row and the in-code default ('1') applies
// again. Platform-wide behaviour change — flagged for sign-off in the
// phase report, not a silent side effect.
return new class extends Migration
{
    private const KEY = 'kyc.require_verification_video';

    public function up(): void
    {
        DB::table('settings')
            ->where('scope_type', 'global')->whereNull('scope_id')->where('key', self::KEY)
            ->delete();

        DB::table('settings')->insert([
            'scope_type' => 'global',
            'scope_id' => null,
            'key' => self::KEY,
            'value' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        cache()->forget('setting:global::'.self::KEY);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('scope_type', 'global')->whereNull('scope_id')->where('key', self::KEY)
            ->delete();

        cache()->forget('setting:global::'.self::KEY);
    }
};

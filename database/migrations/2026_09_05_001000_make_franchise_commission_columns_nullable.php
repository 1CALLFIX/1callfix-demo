<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase: Provider Commercial Rate Resolver — Phase 0/1
//
// Both columns were non-nullable DECIMAL DEFAULT 0 (2026_08_01_001000), which
// made "0" ambiguous: there was no way to tell "an admin deliberately set
// this to 0%" apart from "nobody has ever touched this." NULL now means
// "unconfigured — fall through to the next tier"; a non-null value (0
// included) means an admin explicitly set it.
//
// Only `platform_fee_percent` gets a resolver + a real fallback chain in this
// phase (see App\Services\ProviderCommercialRateResolver). `commission_value`
// (the franchise's own revenue-share cut) is made nullable for schema
// consistency and to leave the door open, but nothing reads it as
// nullable-with-fallback yet — CommissionService still treats a null
// commission_value the same as before this migration would have (0), since
// `commission_model === 'revenue_share'` is the only branch that consumes it
// and a null value multiplies out to 0 either way. That's a deliberate scope
// line, not an oversight — no global default for commission_value was
// requested.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('franchises', function (Blueprint $table) {
            $table->decimal('platform_fee_percent', 5, 2)->nullable()->default(null)->change();
            $table->decimal('commission_value', 8, 2)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Reversible only if no row actually holds NULL — matches the
        // original schema's NOT NULL DEFAULT 0 exactly.
        Schema::table('franchises', function (Blueprint $table) {
            $table->decimal('platform_fee_percent', 5, 2)->nullable(false)->default(0)->change();
            $table->decimal('commission_value', 8, 2)->nullable(false)->default(0)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22.1 (Module Activation Foundation). The single, authoritative
 * geography-scoped activation record the mission brief's cascade
 * (Country -> City -> Zone -> Franchise) resolves against — closing the
 * gap PHASE_22_PLATFORM_CAPABILITY_RECOVERY_AUDIT.md §3/§6 found: no such
 * cascade existed anywhere before this migration, at any level.
 *
 * Deliberately NOT a `Branch` level — the brief's own non-negotiable
 * principle #7 says not to invent one; current geography stops at
 * Franchise (zones.franchise_id makes Zone the more specific child, per
 * Setting::SCOPE_ORDER's own established precedent, which this table's
 * resolution order in ModuleActivationService mirrors exactly).
 *
 * One row = one explicit decision at one exact scope. Absence of a row is
 * NOT the same as "off" — ModuleActivationService::isActive() walks up the
 * chain (zone -> franchise -> city -> country) looking for the first
 * explicit row: an unset level simply defers to its parent, it does not
 * default to inactive at that level specifically. Only once NO level in
 * the chain has an explicit row does the service fall back to its
 * documented default (see that class's own docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type'); // country|city|zone|franchise — validated in ModuleActivationService, not a DB enum (same convention as settings.scope_type/role_assignments.scope_type)
            $table->unsignedBigInteger('scope_id'); // id in the table scope_type names — no single FK target, same reasoning as settings.scope_id
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['module_id', 'scope_type', 'scope_id'], 'module_activations_unique_scope');
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_activations');
    }
};

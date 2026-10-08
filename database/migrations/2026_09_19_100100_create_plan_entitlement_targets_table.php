<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Binds a plan entitlement to the EXISTING master catalog (service_categories /
 * service_subcategories / services) — no parallel catalog.
 *
 * target_type + target_id point at a real catalog row. choice_key ties a target
 * to one option of a choose-one entitlement (Prime Silver's Home Service Credit:
 * electrical | plumbing | carpenter); NULL means the target applies whatever
 * choice is made. is_excluded turns a row into a carve-out (e.g. the whole AC
 * category is covered EXCEPT the Gas Charging service).
 *
 * Targets are configuration, so they cascade with their entitlement. That is
 * safe because PlanEntitlement refuses deletion once it has any subscriber
 * history (balances / ledger rows) — see PlanEntitlement::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_entitlement_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_entitlement_id')->constrained()->cascadeOnDelete();
            $table->enum('target_type', ['category', 'subcategory', 'service']);
            $table->unsignedBigInteger('target_id');
            $table->string('choice_key', 64)->nullable();
            $table->boolean('is_excluded')->default(false);
            $table->timestamps();

            $table->index(['plan_entitlement_id', 'target_type', 'target_id'], 'plan_ent_targets_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_entitlement_targets');
    }
};

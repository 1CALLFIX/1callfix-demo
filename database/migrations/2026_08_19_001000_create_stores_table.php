<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 (Marketplace Foundation). Column shapes verified directly
 * against 6amMart 4.0.1's real `stores` table (the actual admin panel
 * source + its own install SQL dump, not the earlier, emptier
 * "references" copy Phase 22.8 had access to). `provider_id`, not a new
 * "Vendor" table -- see PHASE_24_MARKETPLACE_FOUNDATION_ARCHITECTURE.md
 * §2: 6amMart's own real `Vendor hasMany Store` shape is a genuine
 * two-tier design (account vs. location), and this codebase already has
 * the account tier (`Provider`, already reused this same way by Property
 * Rental's `properties.provider_id`). One `Provider` can own several
 * `Store` rows -- the real multi-location answer Phase 22.8 §6 left open.
 *
 * `module` is a plain indexed string (matching `App\Support\Modules::ALL`
 * keys), not a FK to the `modules` table -- same established convention
 * `service_categories.module` already uses (verified by reading that
 * migration directly), not invented here.
 *
 * Deliberately NOT included, real 6amMart evidence exists for all three
 * but none is required to prove a working checkout (see architecture doc
 * §3): a `store_schedules` day-by-day opening-hours table (`is_open` is a
 * real, simpler, evidenced mechanism on its own), a per-store commission
 * override (the shared `CommissionService::applyForFieldWorkerOrder()`
 * helper only reads `franchise->platform_fee_percent` today -- widening it
 * for an unproven override would be premature generalization), a
 * subscription-package revenue model (`package_id` in 6amMart -- a
 * commercial model 1CallFix has never used anywhere).
 *
 * Ships fully inert: `modules.is_implemented` stays `false` for
 * food/grocery/pharmacy/commerce (Phase 22.1's hard gate) until each
 * vertical's own phase actually implements it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('franchise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module')->index(); // App\Support\Modules::ALL key: food / grocery / pharmacy / commerce

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo')->nullable();
            $table->string('cover_image')->nullable();

            $table->string('address_line');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('phone')->nullable();

            $table->boolean('is_open')->default(true); // real-time toggle (store staff)
            $table->boolean('is_active')->default(true); // admin-level toggle

            $table->boolean('prescription_required')->default(false); // real 6amMart evidence (stores.prescription_order) -- the one evidenced Pharmacy-specific control
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('minimum_order_amount', 10, 2)->default(0);
            $table->decimal('delivery_fee_flat', 10, 2)->default(0);
            $table->decimal('free_delivery_above_amount', 10, 2)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['franchise_id', 'zone_id']);
            $table->index(['module', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};

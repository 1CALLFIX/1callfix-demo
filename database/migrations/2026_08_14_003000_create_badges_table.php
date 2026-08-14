<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Universal Badge Engine. `badges` is the DEFINITION (one row per badge
// TYPE — "NEW", "FEATURED", a custom admin-created one), reusable across
// however many entities carry it, the same "definition vs. instance" split
// Plan/Subscription already established. `mode` decides how instances of
// this badge come to exist:
//   - 'manual'    — an admin explicitly assigns it per entity (badge_assignments row).
//   - 'automatic' — computed live against the entity's own attributes by
//     BadgeService, using `rule_type`/`rule_config` (e.g. rule_type=
//     'recently_created', rule_config={"within_days":14} for NEW) — no
//     row is ever persisted for these, so there's nothing to keep in sync
//     and "automatic disappearance" falls out of the rule evaluation
//     itself rather than a cron job. Config is admin-editable, not a
//     hardcoded threshold — POPULAR/TRENDING/FEATURED/BEST_VALUE/LIMITED/
//     FLASH_SALE ship as 'manual' (no existing popularity/trending
//     statistics engine exists to drive an automatic rule for them
//     honestly — RankingEngine is provider-dispatch ranking, a different
//     domain), NEW ships as 'automatic' with a real, non-invented rule.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // 'new', 'popular', 'flash_sale', or a custom admin-created slug
            $table->string('label'); // "NEW", "FEATURED"
            $table->text('description')->nullable();
            $table->string('icon')->nullable(); // matches components.icon's own $name vocabulary; null = no icon
            $table->string('text_color', 20)->default('#ffffff'); // hex or a Tailwind-safe token, admin-editable
            $table->string('bg_color', 20)->default('#0f172a');
            $table->unsignedInteger('priority')->default(0); // display ordering when an entity carries multiple badges
            $table->enum('mode', ['manual', 'automatic'])->default('manual');
            $table->string('rule_type')->nullable(); // e.g. 'recently_created' -- only meaningful when mode='automatic'
            $table->json('rule_config')->nullable(); // e.g. {"within_days":14} -- admin-configurable, never a hardcoded threshold
            $table->unsignedInteger('default_duration_days')->nullable(); // manual-assignment convenience default; the assignment's own expires_at is still what's enforced
            $table->boolean('is_active')->default(true); // admin can disable the whole badge type without deleting its history
            $table->timestamps();

            $table->index(['mode', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badges');
    }
};

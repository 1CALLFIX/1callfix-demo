<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prime Silver has several `quantity` entitlements that must be told
 * apart ("AC Jet Pump Service" vs "Appliance General Service") and one
 * that is category-agnostic until the moment it is redeemed (the "Home
 * Service Credit" — the customer picks Electrical, Plumbing or Carpenter
 * per use). The engine's automatic booking-time pricing resolver groups
 * purely by `entitlement_type`, so it cannot distinguish these on its
 * own. Two additive nullable columns support an explicit redeem flow
 * (RedeemEntitlementAction) without touching that resolver:
 *
 * - label: human display name for one specific entitlement row.
 * - redeem_categories: when non-null, the finite set of choices the
 *   redeemer must pick exactly one of; the pick is recorded on the
 *   usage_ledger row (usage_ledger.redeemed_category). Null = no choice.
 *
 * Every existing entitlement keeps both null and is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_entitlements', function (Blueprint $table) {
            $table->string('label')->nullable()->after('module');
            $table->json('redeem_categories')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('plan_entitlements', function (Blueprint $table) {
            $table->dropColumn(['label', 'redeem_categories']);
        });
    }
};

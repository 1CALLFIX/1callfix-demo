<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which category a category-agnostic entitlement was redeemed
 * against (Prime Silver's "Home Service Credit" — Electrical / Plumbing /
 * Carpenter, chosen at redemption time, not at plan creation). Nullable:
 * every existing ledger row, and every redemption of an entitlement whose
 * plan_entitlements.redeem_categories is null, leaves this untouched.
 *
 * Append-only table — this is written once at consume() time alongside
 * the rest of the row, never updated afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_ledger', function (Blueprint $table) {
            $table->string('redeemed_category')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('usage_ledger', function (Blueprint $table) {
            $table->dropColumn('redeemed_category');
        });
    }
};

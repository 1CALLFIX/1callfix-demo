<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Coupon engine C2 (owner-approved 2026-10-05) — additive only, nothing backfilled.
//  daily_budget  null = no daily cap (never invented). Today's spend is COMPUTED from coupon_usages
//                (since 00:00 Asia/Kolkata), so there is no counter to drift.
//  funding_mode  string, not enum. Only 'hq' is selectable until the C4 fund ledger exists.
//  campaign_tag  free-text attribution label; no effect on pricing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->decimal('daily_budget', 12, 2)->nullable()->after('total_budget');
            $table->string('funding_mode', 12)->default('hq')->after('daily_budget');
            $table->string('campaign_tag', 60)->nullable()->after('funding_mode');
            $table->index('campaign_tag');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex(['campaign_tag']);
            $table->dropColumn(['daily_budget', 'funding_mode', 'campaign_tag']);
        });
    }
};

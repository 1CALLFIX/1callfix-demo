<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership completion (1CF-MEMBERSHIP-IMPLEMENT-002) — additive columns only.
 *
 *  - plans.validity_months: a real calendar-month validity ("11 months from
 *    activation"). The billing_cycle enum cannot express "N months" and
 *    custom_cycle_days is only an approximation (334 days), so this nullable
 *    column takes precedence in Plan::computePeriodEnd() when set. NULL keeps
 *    every existing plan on exactly the cycle it has today.
 *  - plan_entitlements.redemption_effect: how a deliberately-redeemed benefit
 *    changes a booking's price — 'service_included' (the covered service is
 *    included) or 'visit_fee_waiver' (only the visiting charge is waived).
 *    NULL = the legacy discount behaviour, untouched.
 *  - plan_entitlements.description / includes / excludes: the customer-facing
 *    benefit copy and scope lists, stored as data rather than hard-coded in a view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('validity_months')->nullable()->after('custom_cycle_days');
        });

        Schema::table('plan_entitlements', function (Blueprint $table) {
            $table->string('redemption_effect', 32)->nullable()->after('label');
            $table->text('description')->nullable()->after('redemption_effect');
            $table->json('includes')->nullable()->after('description');
            $table->json('excludes')->nullable()->after('includes');
        });
    }

    public function down(): void
    {
        Schema::table('plan_entitlements', function (Blueprint $table) {
            $table->dropColumn(['redemption_effect', 'description', 'includes', 'excludes']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('validity_months');
        });
    }
};

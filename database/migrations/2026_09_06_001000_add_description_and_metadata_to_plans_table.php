<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prime Silver membership needs its printed-card terms (address-locked,
 * no carry-forward, priority ≠ immediate, spare parts always chargeable,
 * etc.) stored against the plan. `plans` had nowhere for prose:
 * `eligibility_rules` is JSON scoped to eligibility logic, not display
 * text. Two additive nullable columns, no behaviour change to any
 * existing plan (both stay null).
 *
 * - description: free customer-facing summary text.
 * - metadata: structured key/value the app can branch on later without a
 *   further migration (e.g. {"address_locked": true,
 *   "spare_parts_chargeable": true, "validity_note": "..."}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->json('metadata')->nullable()->after('eligibility_rules');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['description', 'metadata']);
        });
    }
};

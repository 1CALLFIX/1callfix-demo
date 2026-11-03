<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Food problem rules (owner 2026-11-03, after the live walk-through): the restaurant answers each food problem —
 * Agree or Dispute (with a reason) — by a deadline from Food Settings; no answer by then counts as Agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_returns', function (Blueprint $table) {
            $table->string('restaurant_response', 10)->nullable()->after('platform_paid');
            $table->string('restaurant_note', 500)->nullable()->after('restaurant_response');
            $table->timestamp('restaurant_respond_by')->nullable()->after('restaurant_note');
            $table->timestamp('restaurant_responded_at')->nullable()->after('restaurant_respond_by');
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_returns', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'created_at']);
            $table->dropColumn(['restaurant_response', 'restaurant_note', 'restaurant_respond_by', 'restaurant_responded_at']);
        });
    }
};

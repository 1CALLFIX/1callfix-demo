<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurants with no billing software (owner 2026-11-12): when the "order waiting — accept now" reminder went to the
 * restaurant (once per order; food:expire-unaccepted sends it before the order would be cancelled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->timestamp('accept_nudged_at')->nullable()->after('accept_by');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropColumn('accept_nudged_at');
        });
    }
};

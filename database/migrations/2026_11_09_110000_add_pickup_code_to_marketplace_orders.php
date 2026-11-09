<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-09: a 4-digit pickup code per delivery order. The rider says it at the counter, the restaurant / shop
 * types it and taps "Handed over" (picked_up_at); only then can the rider deliver. With 10 orders and 6 riders at one
 * counter, every bag goes to the right rider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->string('pickup_code', 8)->nullable()->after('rider_at_store_at');
            $table->timestamp('picked_up_at')->nullable()->after('pickup_code');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropColumn(['pickup_code', 'picked_up_at']);
        });
    }
};

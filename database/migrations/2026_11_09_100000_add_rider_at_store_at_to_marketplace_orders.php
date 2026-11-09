<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-09: the rider taps "I've reached the restaurant" (or shop). Only then does he see the items, the
 * customer and the delivery details. Null = not there yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->timestamp('rider_at_store_at')->nullable()->after('assigned_worker_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropColumn('rider_at_store_at');
        });
    }
};

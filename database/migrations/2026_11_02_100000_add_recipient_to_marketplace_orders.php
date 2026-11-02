<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop checkout polish 2026-11-02: "Send to someone else". A delivery order can name the person who receives it;
 * the rider calls them and they get the delivery code by SMS. Null = the customer receives it (as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->string('recipient_name', 100)->nullable()->after('delivery_address_id');
            $table->string('recipient_phone', 20)->nullable()->after('recipient_name');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropColumn(['recipient_name', 'recipient_phone']);
        });
    }
};

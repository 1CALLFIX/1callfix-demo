<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A benefit can be switched off on a live package without deleting it (a benefit with usage history can never be
     * deleted). Off = not granted in future periods, not redeemable, hidden from customers.
     */
    public function up(): void
    {
        Schema::table('plan_entitlements', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(true)->after('is_approved');
        });
    }

    public function down(): void
    {
        Schema::table('plan_entitlements', function (Blueprint $table) {
            $table->dropColumn('is_enabled');
        });
    }
};

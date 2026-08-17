<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24 (Marketplace Foundation). Same established pattern as every
 * prior vertical's own addition to these three tables -- a plain nullable
 * FK per concrete order type. `dispatch_attempts` needs NO migration here:
 * its `dispatchable_type`/`dispatchable_id` polymorphic columns (added
 * generically in Phase 22.4/Parcel) already accept `MarketplaceOrder`
 * directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->foreignId('marketplace_order_id')->nullable()->after('property_reservation_id')->constrained()->nullOnDelete();
            $table->unique('marketplace_order_id', 'commissions_marketplace_order_id_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('marketplace_order_id')->nullable()->after('property_reservation_id')->constrained()->nullOnDelete();
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride', 'property_reservation', 'marketplace_order'])->default('booking')->change();
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('marketplace_order_id')->nullable()->after('property_reservation_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marketplace_order_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            DB::table('payments')->where('purpose', 'marketplace_order')->update(['purpose' => 'booking']);
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription', 'parcel_order', 'taxi_ride', 'property_reservation'])->default('booking')->change();
            $table->dropConstrainedForeignId('marketplace_order_id');
        });

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropUnique('commissions_marketplace_order_id_unique');
            $table->dropConstrainedForeignId('marketplace_order_id');
        });
    }
};

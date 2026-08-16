<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phase 22.4 (Parcel). `payments` already has an established
// purpose-discriminator + purpose-specific-nullable-FK pattern
// (purpose='wallet_topup' -> user_id, purpose='plan_subscription' ->
// plan_subscription_id — 2026_08_11_025000/037000) — this migration adds
// 'parcel_order' as a third purpose value + parcel_order_id, following
// that exact precedent rather than inventing a new shape.
// `payments.booking_id` loosened to nullable (existing rows all already
// non-null, purely additive); every existing `Payment::where('booking_id',
// ...)` call site continues to work unchanged for real Service payments.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('booking_id')->nullable()->change();
            $table->foreignId('parcel_order_id')->nullable()->after('plan_subscription_id')->constrained()->nullOnDelete();
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription', 'parcel_order'])->default('booking')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Same data-safety step 2026_08_11_037000 used when shrinking
            // this same enum -- revert any parcel_order rows before the
            // enum can no longer hold that value.
            \Illuminate\Support\Facades\DB::table('payments')->where('purpose', 'parcel_order')->update(['purpose' => 'booking']);
            $table->enum('purpose', ['booking', 'wallet_topup', 'plan_subscription'])->default('booking')->change();
            $table->dropConstrainedForeignId('parcel_order_id');
            $table->foreignId('booking_id')->nullable(false)->change();
        });
    }
};

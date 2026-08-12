<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// DB-hardening finding: bookings.coupon_id was a plain nullable foreignId
// with NO constrained() -- unlike coupon_usages.coupon_id and
// notification_campaigns.coupon_id, which both already reference coupons
// properly. Coupon infrastructure is clearly still being actively built
// (those two other FKs), not abandoned, so this completes the same
// pattern rather than inventing a new decision -- nullOnDelete, matching
// notification_campaigns.coupon_id exactly.
//
// Verified safe before writing this migration: production `bookings` has
// 0 rows and 0 non-null coupon_id values (read-only check via tinker,
// this session) -- nothing to orphan or reconcile.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreign('coupon_id', 'bookings_coupon_id_foreign')
                ->references('id')->on('coupons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // dropForeign(string $name) isn't supported on sqlite ("this
            // database driver does not support dropping foreign keys by
            // name") — the column-array form works on both drivers.
            $table->dropForeign(['coupon_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// qualifying_booking_id records WHICH completed booking triggered the
// reward (audit trail, and lets ReferralService recognize "already
// rewarded off booking X" for idempotency). Unique index on referred_id --
// confirmed zero existing rows (audit), safe to add -- enforces "a user
// can only ever be the REFERRED party once" at the database level, not
// just in application code.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('qualifying_booking_id')->nullable()->after('referred_id')->constrained('bookings')->nullOnDelete();
            $table->unique('referred_id');
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropUnique(['referred_id']);
            $table->dropForeign(['qualifying_booking_id']);
            $table->dropColumn('qualifying_booking_id');
        });
    }
};

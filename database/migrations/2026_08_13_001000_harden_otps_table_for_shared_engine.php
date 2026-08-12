<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// otps has existed since Phase 1 (phone, code, purpose, expires_at,
// verified_at) with ZERO consumers anywhere in the codebase (confirmed by
// grep this session) — the working Service booking OTP has always lived on
// bookings.start_otp/completion_otp instead, a separate, deliberately
// untouched mechanism (see OTP_ARCHITECTURE.md's Option A/B/C analysis).
//
// This migration brings the dormant table up to the security shape a real
// shared LOGIN OTP engine needs — hashed code storage, attempt tracking,
// lockout, resend-cooldown support, channel/audit fields — safe to do
// because there are zero rows and zero consumers to break (verified this
// session: `grep -rln "Otp::" app/` returns nothing).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->renameColumn('code', 'code_hash');
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->string('channel')->nullable()->after('purpose');
            $table->unsignedTinyInteger('attempt_count')->default(0)->after('channel');
            $table->unsignedTinyInteger('max_attempts')->default(5)->after('attempt_count');
            $table->enum('status', ['pending', 'verified', 'expired', 'locked'])->default('pending')->after('max_attempts');
            $table->timestamp('last_sent_at')->nullable()->after('status');
            $table->string('ip_address', 45)->nullable()->after('last_sent_at');
            $table->string('device_identifier')->nullable()->after('ip_address');

            // Explicit short name — the auto-generated one
            // (otps_phone_purpose_status_index) is well under the 64-char
            // limit here, named explicitly anyway per the standing
            // entitlement_balances lesson.
            $table->index(['phone', 'purpose', 'status'], 'otps_phone_purpose_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->dropIndex('otps_phone_purpose_status_idx');
            $table->dropColumn(['channel', 'attempt_count', 'max_attempts', 'status', 'last_sent_at', 'ip_address', 'device_identifier']);
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->renameColumn('code_hash', 'code');
        });
    }
};

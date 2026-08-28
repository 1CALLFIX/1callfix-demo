<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E5 — hardening metadata for the existing Service booking OTP
 * (bookings.start_otp / completion_otp). OTP_ARCHITECTURE.md deliberately
 * kept those two codes on the booking row (Option C) and flagged, for the
 * record, that they had no expiry, no attempt limit and no single-use
 * guard — carried forward "as a known gap, not invented as a fix without
 * approval". E5 is that approved, deliberate pass, so the gap is closed
 * IN PLACE on the same row rather than by migrating onto the shared `otps`
 * engine (which would be exactly the untested rewrite of financially
 * sensitive Actions the mission's own rules forbid).
 *
 * Every column is additive and nullable / default-0. A NULL `*_expires_at`
 * means "no expiry" and a NULL `*_verified_at` means "not yet consumed",
 * so every booking that already exists keeps working unchanged — the new
 * enforcement only bites once AcceptBookingAction / AdminReassignBookingAction
 * (the two code paths that generate these OTPs) start stamping the metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('start_otp_expires_at')->nullable()->after('start_otp');
            $table->unsignedTinyInteger('start_otp_attempts')->default(0)->after('start_otp_expires_at');
            $table->timestamp('start_otp_verified_at')->nullable()->after('start_otp_attempts');

            $table->timestamp('completion_otp_expires_at')->nullable()->after('completion_otp');
            $table->unsignedTinyInteger('completion_otp_attempts')->default(0)->after('completion_otp_expires_at');
            $table->timestamp('completion_otp_verified_at')->nullable()->after('completion_otp_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'start_otp_expires_at', 'start_otp_attempts', 'start_otp_verified_at',
                'completion_otp_expires_at', 'completion_otp_attempts', 'completion_otp_verified_at',
            ]);
        });
    }
};

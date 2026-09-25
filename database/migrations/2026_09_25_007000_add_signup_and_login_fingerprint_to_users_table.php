<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — Migration 4, approved 2026-09-25 (R5).
 * The same-IP / same-device referral hold compares the referee's signup
 * fingerprint with the REFERRER's, which nothing recorded before:
 *
 *   signup_ip / signup_device_id        set once, at account creation
 *   last_login_ip / last_login_device_id / last_login_at
 *                                       refreshed on every login (web Login
 *                                       event; API tokenResponse) — gives
 *                                       existing referrers coverage from
 *                                       their next login
 *
 * Device id = the app's X-Device-Id header (web sends none → NULL).
 * Personal data: shown to Super Admin only. Additive, nullable, clean down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_ip', 45)->nullable()->after('referral_blocked_by');
            $table->string('signup_device_id', 191)->nullable()->after('signup_ip');
            $table->string('last_login_ip', 45)->nullable()->after('signup_device_id');
            $table->string('last_login_device_id', 191)->nullable()->after('last_login_ip');
            $table->timestamp('last_login_at')->nullable()->after('last_login_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['signup_ip', 'signup_device_id', 'last_login_ip', 'last_login_device_id', 'last_login_at']);
        });
    }
};

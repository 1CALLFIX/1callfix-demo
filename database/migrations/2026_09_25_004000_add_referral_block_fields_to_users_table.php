<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — approved Migration 2 (R8). A Super Admin
 * can block a customer from the referral program: their code is refused at
 * signup and their pending referrals issue no reward. Block/unblock history
 * lives in activity_log. Additive, nullable, clean down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('referral_blocked_at')->nullable()->after('referred_by');
            $table->string('referral_blocked_reason', 500)->nullable()->after('referral_blocked_at');
            $table->foreignId('referral_blocked_by')->nullable()->after('referral_blocked_reason')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referral_blocked_by']);
            $table->dropColumn(['referral_blocked_by', 'referral_blocked_reason', 'referral_blocked_at']);
        });
    }
};

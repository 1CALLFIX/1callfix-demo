<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Referral Engine hardening (full-day mission Phase 3). Deliberately does
// NOT touch the existing pending->rewarded automatic-qualification flow
// (ReferralService::qualifyFromCompletedBooking(), already tested and
// working) or invent cross-actor qualification rules -- see
// KNOWN_RISKS_AND_DECISIONS.md item 2, still genuinely open. What's added
// here is real, safe, non-invented anti-abuse infrastructure:
//
// - 'expired': a pending referral that never qualifies within an
//   ADMIN-CONFIGURABLE window (referral.pending_expiry_days Setting,
//   default null = never expires -- opt-in, not a mandatory invented
//   number).
// - 'fraud_flagged': an admin-driven manual review outcome (this session
//   builds no automatic fraud-DETECTION -- the actual signal thresholds
//   are KNOWN_RISKS_AND_DECISIONS.md item 3, genuinely pending). Flagging
//   an already-rewarded referral attempts a wallet clawback, recorded via
//   reversed_at/reversal_note regardless of whether the debit itself
//   succeeded (a referrer who already spent the reward can't be forced
//   negative -- WalletService::debit() already refuses that; the flag and
//   audit trail still record the attempt honestly).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->enum('status', ['pending', 'rewarded', 'expired', 'fraud_flagged'])->default('pending')->change();
            $table->timestamp('expires_at')->nullable()->after('qualifying_booking_id');
            $table->timestamp('fraud_flagged_at')->nullable()->after('status');
            $table->foreignId('fraud_flagged_by')->nullable()->after('fraud_flagged_at')->constrained('users')->nullOnDelete();
            $table->text('fraud_notes')->nullable()->after('fraud_flagged_by');
            $table->timestamp('reversed_at')->nullable()->after('fraud_notes');
            $table->text('reversal_note')->nullable()->after('reversed_at');
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropForeign(['fraud_flagged_by']);
            $table->dropColumn(['expires_at', 'fraud_flagged_at', 'fraud_flagged_by', 'fraud_notes', 'reversed_at', 'reversal_note']);
            $table->enum('status', ['pending', 'rewarded'])->default('pending')->change();
        });
    }
};

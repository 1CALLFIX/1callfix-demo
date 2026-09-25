<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — Migration 3, approved 2026-09-25 with
 * additions (referee_reward_points, referee_rewarded_at). The requested
 * status index already exists — referrals_status_idx, added by
 * 2026_08_15_010000_add_scale_indexes_to_hot_status_columns — so none is
 * added here.
 *
 *   status              + held_review (R5 fraud hold), + rejected (R6, final)
 *   signup_ip           referee's signup IP (R5) — personal data, Super Admin only
 *   signup_device_id    X-Device-Id header from the app (R5) — personal data
 *   hold_reason         why it is held (same_signup_ip / same_device)
 *   no_reward_reason    why a referral earned / will earn no reward (R3, R8, D5)
 *   reviewed_at/_by, review_note   Super Admin release / reject (R6)
 *   referee_reward_amount / referee_reward_points / referee_rewarded_at  (R7)
 *     — amount for a wallet reward, points for a points reward, mirroring the
 *     referrer side (reward_amount; a points reward writes a ref-keyed
 *     loyalty row). referee_rewarded_at is the once-only marker.
 *
 * Status widened with ->change(), same precedent as payments.purpose
 * (2026_08_28_003000). Existing rows keep their status and get NULL in every
 * new column — no backfill. down() refuses while any row uses a new status.
 */
return new class extends Migration
{
    private const OLD = ['pending', 'rewarded', 'expired', 'fraud_flagged'];

    private const NEW = ['pending', 'held_review', 'rewarded', 'expired', 'fraud_flagged', 'rejected'];

    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->enum('status', self::NEW)->default('pending')->change();

            $table->string('signup_ip', 45)->nullable()->after('expires_at');
            $table->string('signup_device_id', 191)->nullable()->after('signup_ip');
            $table->string('hold_reason')->nullable()->after('signup_device_id');
            $table->string('no_reward_reason')->nullable()->after('hold_reason');
            $table->timestamp('reviewed_at')->nullable()->after('no_reward_reason');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable()->after('reviewed_by');
            $table->decimal('referee_reward_amount', 10, 2)->nullable()->after('reward_amount');
            $table->unsignedInteger('referee_reward_points')->nullable()->after('referee_reward_amount');
            $table->timestamp('referee_rewarded_at')->nullable()->after('referee_reward_points');
        });
    }

    public function down(): void
    {
        $inUse = DB::table('referrals')->whereIn('status', ['held_review', 'rejected'])->count();
        if ($inUse > 0) {
            throw new RuntimeException(
                "Refusing to roll back: {$inUse} referral(s) have status held_review or rejected, "
                .'which the old status enum cannot store. Release or reject-and-archive them first; '
                .'this migration will not silently rewrite a referral decision.'
            );
        }

        Schema::table('referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn([
                'signup_ip', 'signup_device_id', 'hold_reason', 'no_reward_reason', 'reviewed_at', 'review_note',
                'referee_reward_amount', 'referee_reward_points', 'referee_rewarded_at',
            ]);
            $table->enum('status', self::OLD)->default('pending')->change();
        });
    }
};

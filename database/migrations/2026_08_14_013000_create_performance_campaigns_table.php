<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Performance/Growth Campaign Engine (mission Phase 1). NOT the same thing
// as notification_campaigns (CampaignService/NotificationCampaign) -- that
// is a broadcast/messaging engine (send a message to an audience). This is
// an INCENTIVE engine: track a real, measurable metric against a target
// over a time window, rank/qualify participants, and pay a configured
// reward through the EXISTING WalletService/LoyaltyService/BadgeService
// rails -- no parallel payout mechanism, no invented reward amounts or
// target values (both are admin-supplied at creation time, validated but
// never defaulted to a business number).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // Who this campaign measures. Franchise campaigns pay out to the
            // franchise's owner_user_id (the only wallet a Franchise row can
            // reach) -- a structural mapping, not a commercial decision; see
            // PerformanceCampaign::rewardRecipientFor().
            $table->enum('audience_type', ['franchise', 'provider', 'field_worker', 'customer']);

            // Same scope vocabulary as Badge/FlashSale/NotificationCampaign.
            $table->enum('scope_type', ['global', 'country', 'city', 'zone', 'franchise'])->default('global');
            $table->unsignedBigInteger('scope_id')->nullable();

            // Which real, existing-data metric this campaign measures. The
            // engine only knows how to compute a fixed, honest set of
            // metrics (CampaignMetricResolver::SUPPORTED) -- it does not
            // accept an arbitrary admin-typed formula.
            $table->string('metric_key');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // How a participant's metric_value qualifies them for a reward.
            // threshold: metric_value >= target_value qualifies (target_value required).
            // top_n: the top `top_n` participants by metric_value qualify (top_n required).
            $table->enum('qualification_mode', ['threshold', 'top_n']);
            $table->decimal('target_value', 12, 2)->nullable();
            $table->unsignedInteger('top_n')->nullable();

            // Reward configuration -- reuses an EXISTING financial rail,
            // never a new one. reward_value is admin-set, never defaulted.
            $table->enum('reward_type', ['wallet_credit', 'loyalty_points', 'badge']);
            $table->decimal('reward_value', 12, 2)->nullable();
            $table->foreignId('badge_id')->nullable()->constrained('badges')->nullOnDelete();

            // Reward payout requires an explicit admin approval step before
            // any wallet/points/badge mutation happens -- see
            // PerformanceCampaignService::TRANSITIONS and approve()/disburse().
            $table->boolean('requires_approval')->default(true);

            $table->enum('status', [
                'draft', 'scheduled', 'active', 'paused', 'completed',
                'under_review', 'approved', 'rewarded', 'closed', 'cancelled',
            ])->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['audience_type', 'status']);
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_campaigns');
    }
};

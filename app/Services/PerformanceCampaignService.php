<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\PerformanceCampaign;
use App\Models\PerformanceCampaignParticipant;
use App\Services\Campaigns\CampaignMetricResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Performance/Growth Campaign lifecycle, progress tracking, and reward
 * disbursement. Reuses WalletService/LoyaltyService/BadgeService for every
 * actual financial/points/badge effect — this service never mutates a
 * wallet balance directly (mission rule #14) and never invents a reward
 * amount, target, or ranking cutoff (all admin-supplied at creation time).
 */
class PerformanceCampaignService
{
    /**
     * Explicit status-column transitions. 'under_review'->'approved' and
     * 'approved'->'rewarded' are the only two edges that unlock money
     * moving (see approve()/disburse()) and both require the separate
     * `performance_campaigns.approve` permission at the Livewire layer —
     * this map only governs which transitions are legal at all, not who
     * may invoke them.
     */
    private const TRANSITIONS = [
        'draft' => ['scheduled', 'cancelled'],
        'scheduled' => ['active', 'cancelled'],
        'active' => ['paused', 'completed', 'cancelled'],
        'paused' => ['active', 'cancelled'],
        'completed' => ['under_review', 'cancelled'],
        'under_review' => ['approved', 'active', 'cancelled'],
        'approved' => ['rewarded', 'cancelled'],
        'rewarded' => ['closed'],
        'closed' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private CampaignMetricResolver $resolver,
        private AuthorizationService $authz,
        private WalletService $wallet,
        private LoyaltyService $loyalty,
        private BadgeService $badges,
    ) {
    }

    private function transition(PerformanceCampaign $campaign, string $to): PerformanceCampaign
    {
        $allowed = self::TRANSITIONS[$campaign->status] ?? [];

        if (! in_array($to, $allowed, true)) {
            throw new \RuntimeException("Cannot move campaign #{$campaign->id} from '{$campaign->status}' to '{$to}'.");
        }

        $campaign->status = $to;
        $campaign->save();

        return $campaign->fresh();
    }

    public function schedule(PerformanceCampaign $campaign): PerformanceCampaign
    {
        if (! $campaign->starts_at || ! $campaign->ends_at) {
            throw new \RuntimeException('A campaign needs both a start and an end date before it can be scheduled.');
        }
        if ($campaign->ends_at->lessThanOrEqualTo($campaign->starts_at)) {
            throw new \RuntimeException('The campaign must end after it starts.');
        }

        return $this->transition($campaign, 'scheduled');
    }

    public function activate(PerformanceCampaign $campaign): PerformanceCampaign
    {
        return $this->transition($campaign, 'active');
    }

    public function pause(PerformanceCampaign $campaign): PerformanceCampaign
    {
        return $this->transition($campaign, 'paused');
    }

    public function resume(PerformanceCampaign $campaign): PerformanceCampaign
    {
        return $this->transition($campaign, 'active');
    }

    public function cancel(PerformanceCampaign $campaign): PerformanceCampaign
    {
        return $this->transition($campaign, 'cancelled');
    }

    /** Ends measurement and moves to completed — refreshProgress() should be called once more right before/after this to capture the final snapshot. */
    public function complete(PerformanceCampaign $campaign): PerformanceCampaign
    {
        return $this->transition($campaign, 'completed');
    }

    public function submitForReview(PerformanceCampaign $campaign): PerformanceCampaign
    {
        return $this->transition($campaign, 'under_review');
    }

    /** The only place status moves to 'approved' — gated at the Livewire layer by the separate performance_campaigns.approve permission. */
    public function approve(PerformanceCampaign $campaign): PerformanceCampaign
    {
        return $this->transition($campaign, 'approved');
    }

    public function reopen(PerformanceCampaign $campaign): PerformanceCampaign
    {
        return $this->transition($campaign, 'active');
    }

    /** Final archive step after rewards have been disbursed. */
    public function close(PerformanceCampaign $campaign): PerformanceCampaign
    {
        return $this->transition($campaign, 'closed');
    }

    /**
     * Recomputes every candidate actor's metric_value from real Booking
     * data, upserts (never duplicate-inserts) their participant row, then
     * ranks and marks qualification. Safe to call repeatedly at any point
     * in the campaign's life — it is the ONLY source of progress, there is
     * no separate incremental counter that could drift from reality.
     *
     * Out-of-scope actors are never even considered (the scopeCovers()
     * check happens before a participant row is ever written) — this is
     * the "out-of-scope participant access" prevention the mission asked
     * for, enforced at write time, not just at read time.
     */
    public function refreshProgress(PerformanceCampaign $campaign): PerformanceCampaign
    {
        $actors = $this->resolver->candidateActors($campaign)
            ->filter(function ($actor) use ($campaign) {
                $ancestry = $this->resolver->ancestryFor($this->authz, $campaign, $actor);

                return $this->authz->scopeCovers($campaign->scope_type, $campaign->scope_id, $ancestry);
            });

        foreach ($actors as $actor) {
            $value = $this->resolver->valueFor($campaign, $actor);

            PerformanceCampaignParticipant::updateOrCreate(
                [
                    'performance_campaign_id' => $campaign->id,
                    'participant_type' => get_class($actor),
                    'participant_id' => $actor->getKey(),
                ],
                ['metric_value' => $value]
            );
        }

        $this->rankAndQualify($campaign);

        return $campaign->fresh();
    }

    private function rankAndQualify(PerformanceCampaign $campaign): void
    {
        $participants = PerformanceCampaignParticipant::where('performance_campaign_id', $campaign->id)
            ->whereNull('disqualified_reason')
            ->orderByDesc('metric_value')
            ->get();

        $rank = 0;
        $previousValue = null;
        $tieRank = 0;

        foreach ($participants as $participant) {
            $rank++;
            // Tie-safe ranking: equal metric_value gets equal rank (standard
            // competition ranking), so a tie at the top_n cutoff qualifies
            // every tied participant rather than an arbitrary DB-order pick.
            $currentValue = (float) $participant->metric_value;
            if ($previousValue === null || abs($currentValue - $previousValue) > 0.001) {
                $tieRank = $rank;
            }
            $previousValue = $currentValue;

            $qualifies = $campaign->qualification_mode === 'threshold'
                ? $campaign->target_value !== null && $participant->metric_value >= $campaign->target_value
                : $campaign->top_n !== null && $tieRank <= $campaign->top_n;

            $participant->rank = $tieRank;
            $participant->qualified = $qualifies;
            $participant->reward_status = $qualifies ? ($participant->reward_status === 'paid' ? 'paid' : 'pending') : 'not_applicable';
            $participant->save();
        }
    }

    /**
     * Pays every qualified, not-yet-paid participant through the reward
     * type configured on the campaign — the ONLY place this happens, and
     * only reachable from 'approved' (see TRANSITIONS), so a reward can
     * never be disbursed without the explicit approval step. Row-locks the
     * campaign for the whole pass so two concurrent disburse() calls (e.g.
     * a double form submit) can't both pay the same participant twice —
     * same DB::transaction()+lockForUpdate() convention as
     * FlashSaleService::redeem()/AcceptBookingAction.
     */
    public function disburse(PerformanceCampaign $campaign): PerformanceCampaign
    {
        return DB::transaction(function () use ($campaign) {
            $locked = PerformanceCampaign::lockForUpdate()->findOrFail($campaign->id);

            if ($locked->status !== 'approved') {
                throw new \RuntimeException("Cannot disburse rewards for a campaign in status '{$locked->status}' — it must be 'approved' first.");
            }

            $participants = PerformanceCampaignParticipant::where('performance_campaign_id', $locked->id)
                ->where('qualified', true)
                ->where('reward_status', '!=', 'paid')
                ->get();

            foreach ($participants as $participant) {
                $this->payOne($locked, $participant);
            }

            return $this->transition($locked, 'rewarded');
        });
    }

    private function payOne(PerformanceCampaign $campaign, PerformanceCampaignParticipant $participant): void
    {
        $actorClass = $campaign->participantModelClass();
        $actor = $actorClass::find($participant->participant_id);

        if (! $actor) {
            $participant->update(['reward_status' => 'rejected', 'disqualified_reason' => 'Participant record no longer exists.']);
            return;
        }

        $recipient = $campaign->rewardRecipientFor($actor);
        $ref = "perf_campaign:{$campaign->id}:participant:{$participant->id}";

        try {
            match ($campaign->reward_type) {
                'wallet_credit' => $this->payWallet($campaign, $recipient, $ref),
                'loyalty_points' => $this->payLoyaltyPoints($campaign, $recipient, $ref),
                'badge' => $this->payBadge($campaign, $actor),
            };

            $participant->update([
                'reward_status' => 'paid',
                'reward_amount' => $campaign->reward_type === 'badge' ? null : $campaign->reward_value,
                'reward_ref' => $ref,
            ]);
        } catch (\Throwable $e) {
            // Never let one participant's failure (e.g. recipient has no
            // wallet-eligible User, or a duplicate badge) silently swallow
            // the whole batch or crash the transaction for everyone else —
            // record it against that participant's own row and continue.
            $participant->update(['reward_status' => 'rejected', 'disqualified_reason' => Str::limit($e->getMessage(), 190)]);
        }
    }

    private function payWallet(PerformanceCampaign $campaign, $recipient, string $ref): void
    {
        if (! $recipient) {
            throw new \RuntimeException('No wallet-eligible recipient for this participant.');
        }

        $this->wallet->credit($recipient, (float) $campaign->reward_value, "Performance campaign reward: {$campaign->name}", $ref);
    }

    private function payLoyaltyPoints(PerformanceCampaign $campaign, $recipient, string $ref): void
    {
        if (! $recipient) {
            throw new \RuntimeException('No loyalty-eligible recipient for this participant.');
        }

        $this->loyalty->earn($recipient, (int) $campaign->reward_value, "Performance campaign reward: {$campaign->name}");
    }

    private function payBadge(PerformanceCampaign $campaign, $actor): void
    {
        if (! $campaign->badge_id) {
            throw new \RuntimeException('This campaign has no reward badge configured.');
        }

        $this->badges->assign(Badge::findOrFail($campaign->badge_id), $actor, $campaign->scope_type, $campaign->scope_id);
    }
}

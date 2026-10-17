<?php

namespace App\Services\Referrals;

use App\Models\LoyaltyPoint;
use App\Models\Referral;
use App\Models\User;
use App\Support\EarningsSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — Stage 4. The ONE customer-facing view of
 * a customer's referrals, shared by the Earnings → Referrals tab and
 * GET /api/referrals/mine so the two can never disagree on what is shown,
 * how a status reads, or whether the feature is visible at all.
 *
 * Never exposes personal data (signup IP / device, the referee's phone or
 * full name): only the referee's first name.
 */
class ReferralPresenter
{
    /** Plain-language status (held_review → "Under review"). */
    public const STATUS_LABELS = [
        'pending' => 'Waiting for a booking paid online',
        'held_review' => 'Under review',
        'rewarded' => 'Rewarded',
        'expired' => 'Expired',
        'fraud_flagged' => 'Reversed',
        'rejected' => 'Not eligible',
    ];

    /** Both switches must be ON for this customer: the Referrals tab and the program itself. */
    public function visibleFor(User $user): bool
    {
        // Only customers can refer (ReferralCapture rejects any other referrer's code).
        return $user->role === 'customer' && EarningsSettings::customerTabOn('earnings.referral_tab', self::scopeFor($user));
    }

    public static function scopeFor(User $user): array
    {
        return array_filter(['franchise_id' => $user->franchise_id, 'zone_id' => $user->zone_id]);
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? Str::headline($status);
    }

    public function shareLink(User $user): ?string
    {
        return $user->referral_code
            ? rtrim((string) config('app.url'), '/').'/signup?ref='.rawurlencode($user->referral_code)
            : null;
    }

    public function paginate(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $page = Referral::query()
            ->with('referred:id,name')
            ->where('referrer_id', $user->id)
            ->latest('id')
            ->paginate($perPage);

        // Points rewards live in the loyalty ledger (reward_amount is 0 for
        // them) — one query for the whole page, not one per row.
        $points = LoyaltyPoint::query()
            ->where('user_id', $user->id)
            ->whereIn('ref', $page->getCollection()->map(fn ($r) => "referral:{$r->id}:points-reward"))
            ->pluck('points', 'ref');

        $page->setCollection($page->getCollection()->map(fn (Referral $r) => [
            'id' => $r->id,
            'friend' => Str::of($r->referred->name ?? 'Friend')->before(' ')->toString(),
            'status' => $r->status,
            'status_label' => self::statusLabel($r->status),
            'joined_at' => $r->created_at?->toIso8601String(),
            'reward_amount' => $r->status === 'rewarded' && (float) $r->reward_amount > 0 ? round((float) $r->reward_amount, 2) : null,
            'reward_points' => $r->status === 'rewarded' ? ((int) ($points["referral:{$r->id}:points-reward"] ?? 0) ?: null) : null,
        ]));

        return $page;
    }

    /** Rules text, built from the live settings for this customer's scope. */
    public function rules(User $user): array
    {
        $scope = self::scopeFor($user);
        $type = EarningsSettings::raw('referral.reward_type', $scope);
        $amount = EarningsSettings::number('referral.reward_amount', $scope);
        $points = EarningsSettings::integer('referral.reward_points', $scope);
        $cap = EarningsSettings::integer('referral.max_per_customer', $scope);
        $refType = EarningsSettings::raw('referral.referee_reward_type');
        $refAmount = EarningsSettings::number('referral.referee_reward_amount');
        $refPoints = EarningsSettings::integer('referral.referee_reward_points');

        return [
            'reward_type' => in_array($type, ['wallet', 'points'], true) ? $type : null,
            'reward_amount' => $type === 'wallet' ? $amount : null,
            'reward_points' => $type === 'points' ? $points : null,
            'max_rewarded_referrals' => $cap,
            'friend_reward_type' => in_array($refType, ['wallet', 'points'], true) ? $refType : null,
            'friend_reward_amount' => $refType === 'wallet' ? $refAmount : null,
            'friend_reward_points' => $refType === 'points' ? $refPoints : null,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\LoyaltyPoint;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LoyaltyPointsNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/**
 * loyalty_points is a ledger, same shape as wallet_transactions -- each row
 * is one earn/redeem/expiry event, balance is a live SUM(), not a stored
 * counter. Points themselves are NOT money; redeem() is the one place they
 * turn into real Rupees, and that conversion is a WalletService::credit()
 * call (the existing, only financial ledger) -- there is no second
 * "loyalty wallet". Every earn is idempotent per (user, booking, reason),
 * mirroring CommissionService::applyForBooking()'s own idempotency check.
 */
class LoyaltyService
{
    public function __construct(private WalletService $walletService)
    {
    }

    public function earn(User $user, int $points, string $reason, ?Booking $booking = null, array $scope = []): ?LoyaltyPoint
    {
        if ($points <= 0) {
            return null;
        }

        if ($booking && LoyaltyPoint::where('user_id', $user->id)->where('booking_id', $booking->id)->where('reason', $reason)->exists()) {
            return null; // already awarded for this exact booking+reason -- safe to call more than once
        }

        $expiryDays = (int) Setting::get('loyalty.points_expiry_days', '365', $scope);

        $entry = LoyaltyPoint::create([
            'user_id' => $user->id,
            'points' => $points,
            'reason' => $reason,
            'booking_id' => $booking?->id,
            'expires_at' => $expiryDays > 0 ? now()->addDays($expiryDays) : null,
        ]);

        $channels = ChannelResolver::resolve($scope);
        $user->notify(new LoyaltyPointsNotification('earned', $points, $this->balance($user), $channels));

        return $entry;
    }

    /**
     * Only earn-rows still within their expiry window count -- an expired
     * earn simply stops contributing to the sum. Redemption/expiry rows
     * are always negative and always count.
     */
    public function balance(User $user): int
    {
        return (int) LoyaltyPoint::where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('points', '<', 0)
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->sum('points');
    }

    /**
     * Converts points to a real wallet credit at the configured rate.
     * Throws on insufficient balance or below the configured minimum --
     * enforced here, not just in a UI form, so a direct API call can't
     * bypass it.
     */
    public function redeem(User $user, int $points, array $scope = []): array
    {
        if ($points <= 0) {
            throw new \InvalidArgumentException('Redemption points must be positive.');
        }

        $minRedemption = (int) Setting::get('loyalty.min_redemption_points', '100', $scope);
        if ($points < $minRedemption) {
            throw new \RuntimeException("Minimum redemption is {$minRedemption} points.");
        }

        $balance = $this->balance($user);
        if ($points > $balance) {
            throw new \RuntimeException("Insufficient points balance: has {$balance}, requested {$points}.");
        }

        $pointsPerRupee = max(1, (int) Setting::get('loyalty.points_per_rupee_redemption', '10', $scope));
        $rupees = round($points / $pointsPerRupee, 2);

        return DB::transaction(function () use ($user, $points, $rupees, $scope) {
            LoyaltyPoint::create([
                'user_id' => $user->id,
                'points' => -$points,
                'reason' => 'redeemed',
            ]);

            $this->walletService->credit(
                $user,
                $rupees,
                reason: "Redeemed {$points} loyalty points",
                ref: 'loyalty-redeem:'.\Illuminate\Support\Str::uuid()
            );

            $channels = ChannelResolver::resolve($scope);
            $user->notify(new LoyaltyPointsNotification('redeemed', $points, $this->balance($user), $channels, $rupees));

            return ['points_redeemed' => $points, 'rupees_credited' => $rupees, 'new_balance' => $this->balance($user)];
        });
    }
}

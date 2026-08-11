<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ReferralStatusNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Facades\DB;

/**
 * referrals is the existing table (referrer_id, referred_id, reward_amount,
 * status) -- confirmed zero prior consumers. Reward reuses the existing
 * financial rails: 'wallet' type credits via WalletService (the only
 * ledger), 'points' type credits via LoyaltyService (which itself only
 * ever turns into money through WalletService too) -- no separate
 * referral wallet/balance.
 */
class ReferralService
{
    public function __construct(private WalletService $walletService, private LoyaltyService $loyaltyService)
    {
    }

    /**
     * Called from UserObserver::created() -- works regardless of WHERE the
     * user was created (a future signup API, an admin form, a test
     * fixture), same reasoning as the other observers already registered.
     * users.referred_by is expected to already hold the resolved
     * referrer's id (set by whatever created the user, after looking up
     * their referral_code) -- not the code itself.
     */
    public function createFromSignup(User $newUser): ?Referral
    {
        if (! $newUser->referred_by) {
            return null;
        }

        $referrer = User::find($newUser->referred_by);

        if (! $referrer || $referrer->id === $newUser->id) {
            return null; // no such referrer, or a self-referral attempt
        }

        if (Referral::where('referred_id', $newUser->id)->exists()) {
            return null; // already referred once -- also enforced by the DB unique index
        }

        return Referral::create([
            'referrer_id' => $referrer->id,
            'referred_id' => $newUser->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Called from CompleteBookingAction. Qualification condition: the
     * referred user's FIRST EVER completed booking. Idempotent via the
     * 'pending' status guard -- a referral can only be rewarded once,
     * ever, and once rewarded this is a no-op on any later booking.
     */
    public function qualifyFromCompletedBooking(Booking $booking, array $scope = []): ?Referral
    {
        $referral = Referral::where('referred_id', $booking->customer_id)->where('status', 'pending')->first();

        if (! $referral) {
            return null;
        }

        $completedCount = Booking::where('customer_id', $booking->customer_id)->where('status', 'completed')->count();

        if ($completedCount !== 1) {
            return null; // not their first completed booking
        }

        return DB::transaction(function () use ($referral, $booking, $scope) {
            $referrer = $referral->referrer;
            $rewardType = Setting::get('referral.reward_type', 'wallet', $scope);

            if ($rewardType === 'points') {
                $points = (int) Setting::get('referral.reward_points', '100', $scope);
                $this->loyaltyService->earn($referrer, $points, 'referral_reward', $booking, $scope);
                $referral->reward_amount = 0;
            } else {
                $amount = (float) Setting::get('referral.reward_amount', '50', $scope);
                if ($amount > 0) {
                    $this->walletService->credit(
                        $referrer,
                        $amount,
                        reason: "Referral reward for referring {$referral->referred?->name}",
                        ref: "referral:{$referral->id}:reward"
                    );
                }
                $referral->reward_amount = $amount;
            }

            $referral->status = 'rewarded';
            $referral->qualifying_booking_id = $booking->id;
            $referral->save();

            $channels = ChannelResolver::resolve($scope);
            $referrer->notify(new ReferralStatusNotification('rewarded', $referral->fresh(), $channels));

            return $referral->fresh();
        });
    }
}

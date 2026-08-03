<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Commission;
use Illuminate\Support\Facades\DB;

class CommissionService
{
    public function __construct(private WalletService $walletService)
    {
    }

    /**
     * Splits a completed booking's final price into provider / franchise /
     * platform shares, credits the provider's wallet, and records the split
     * permanently in `commissions` for reporting.
     *
     * Split logic, using the franchise's own configured rates:
     *   platform_commission = price_final * franchise.platform_fee_percent / 100
     *   franchise_commission = price_final * franchise.commission_value / 100
     *                           (only if commission_model = 'revenue_share' —
     *                           flat_fee and subscription_only franchises don't
     *                           take a per-booking cut, they're paid separately)
     *   provider_commission = whatever's left over
     *
     * Idempotent: if a Commission row already exists for this booking, this
     * is a no-op — safe to call more than once (e.g. a retried job) without
     * double-crediting the provider's wallet.
     */
    public function applyForBooking(Booking $booking): Commission
    {
        $existing = Commission::where('booking_id', $booking->id)->first();
        if ($existing) {
            return $existing;
        }

        $booking->loadMissing(['franchise', 'provider.user']);
        $franchise = $booking->franchise;
        $total = (float) ($booking->price_final ?? $booking->price_quoted);

        $platformCommission = round($total * ($franchise->platform_fee_percent / 100), 2);

        $franchiseCommission = $franchise->commission_model === 'revenue_share'
            ? round($total * ($franchise->commission_value / 100), 2)
            : 0.0;

        $providerCommission = round($total - $platformCommission - $franchiseCommission, 2);

        return DB::transaction(function () use ($booking, $providerCommission, $franchiseCommission, $platformCommission) {
            $commission = Commission::create([
                'booking_id' => $booking->id,
                'provider_commission' => $providerCommission,
                'franchise_commission' => $franchiseCommission,
                'platform_commission' => $platformCommission,
            ]);

            if ($booking->provider && $booking->provider->user && $providerCommission > 0) {
                $this->walletService->credit(
                    $booking->provider->user,
                    $providerCommission,
                    reason: "Earnings for booking {$booking->code}",
                    ref: "booking:{$booking->id}:provider-earning"
                );
            }

            return $commission;
        });
    }
}

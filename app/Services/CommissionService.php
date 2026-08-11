<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Commission;
use App\Models\EntitlementBalance;
use App\Services\Plans\EntitlementService;
use App\Services\Plans\UsageService;
use Illuminate\Support\Facades\DB;

class CommissionService
{
    public function __construct(
        private WalletService $walletService,
        private EntitlementService $entitlementService,
        private UsageService $usageService,
    ) {
    }

    /**
     * Splits a completed booking's final price into provider / franchise /
     * platform shares, credits the provider's wallet, and records the split
     * permanently in `commissions` for reporting.
     *
     * Split logic, using the franchise's own configured rates:
     *   platform_commission = price_final * platform_fee_percent / 100
     *   franchise_commission = price_final * franchise.commission_value / 100
     *                           (only if commission_model = 'revenue_share' —
     *                           flat_fee and subscription_only franchises don't
     *                           take a per-booking cut, they're paid separately)
     *   provider_commission = whatever's left over
     *
     * platform_fee_percent is normally franchise.platform_fee_percent, but a
     * provider's active commission_reduction/commission_override entitlement
     * (Plan Engine, resolved at service_completed per the approved plan §6)
     * can adjust it — the SAME split/commission-row/wallet-credit logic
     * below runs either way, just with a different input rate. No second
     * commission calculation exists anywhere.
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

        $booking->loadMissing(['franchise.owner', 'provider.user']);
        $franchise = $booking->franchise;
        $total = (float) ($booking->price_final ?? $booking->price_quoted);

        $rateOverride = $booking->provider ? $this->entitlementService->resolveCommissionRateOverride($booking->provider) : null;
        $platformFeePercent = $this->resolvePlatformFeePercent($franchise->platform_fee_percent, $rateOverride);

        $platformCommission = round($total * ($platformFeePercent / 100), 2);

        $franchiseCommission = $franchise->commission_model === 'revenue_share'
            ? round($total * ($franchise->commission_value / 100), 2)
            : 0.0;

        $providerCommission = round($total - $platformCommission - $franchiseCommission, 2);

        return DB::transaction(function () use ($booking, $providerCommission, $franchiseCommission, $platformCommission, $rateOverride) {
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

            // Franchise revenue share -> the franchise owner's wallet, same
            // mechanism as the provider's earning above (reusing
            // WalletService, not a second ledger). If no owner is assigned
            // yet (Franchises\Manage's Edit modal), the share is still
            // recorded on this Commission row for later settlement once one
            // is — it just isn't credited anywhere until then.
            if ($booking->franchise && $booking->franchise->owner_user_id && $franchiseCommission > 0) {
                $this->walletService->credit(
                    $booking->franchise->owner,
                    $franchiseCommission,
                    reason: "Franchise revenue share for booking {$booking->code}",
                    ref: "booking:{$booking->id}:franchise-earning"
                );
            }

            // Auditable record that this booking's commission used a
            // plan-adjusted rate — a zero-delta ledger row (the rate change
            // isn't a quantity/monetary consumption in itself, just a fact
            // worth recording against the entitlement's usage history).
            if ($rateOverride) {
                $balance = EntitlementBalance::where('subscription_id', $rateOverride['subscription']->id)
                    ->where('plan_entitlement_id', $rateOverride['entitlement']->id)
                    ->where('status', 'current')
                    ->first();
                if ($balance) {
                    $this->usageService->consume(
                        $balance, 0, 0, $booking, false, null,
                        'Commission rate adjusted via '.$rateOverride['entitlement']->entitlement_type.' for booking '.$booking->code
                    );
                }
            }

            return $commission;
        });
    }

    private function resolvePlatformFeePercent(float $defaultPercent, ?array $rateOverride): float
    {
        if (! $rateOverride) {
            return $defaultPercent;
        }

        $entitlement = $rateOverride['entitlement'];

        return $entitlement->entitlement_type === 'commission_override'
            ? (float) $entitlement->percentage_value
            : max(0.0, $defaultPercent - (float) $entitlement->percentage_value);
    }
}

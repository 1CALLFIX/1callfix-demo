<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Commission;
use App\Models\EntitlementBalance;
use App\Models\ParcelOrder;
use App\Models\PropertyReservation;
use App\Models\TaxiRide;
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

    /**
     * Phase 22.4 (Parcel) — the second real Orderable implementer, per
     * Phase 22.2's own plan. Built by extracting the smallest genuinely
     * shared piece from applyForBooking() above (the split-calculate +
     * commission-row + wallet-credit sequence) rather than forcing this
     * whole class behind an `Orderable`-typed method signature: Parcel has
     * no Plan-Entitlement commission-rate-override concept (that's a
     * Provider/Plan-Engine specific mechanism — FieldWorkers don't hold
     * Provider entitlements), so `applyForParcelOrder()` simply never
     * computes one, rather than threading a permanently-null parameter
     * through a "generalized" applyForBooking(). Idempotent the same way
     * (a `Commission` row already existing for this parcel order is a
     * no-op), via the new `commissions.parcel_order_id` unique constraint
     * (migration 2026_08_17_004000) backstopping the same application-level
     * check `applyForBooking()` already relies on.
     */
    public function applyForParcelOrder(ParcelOrder $order): Commission
    {
        $order->loadMissing(['franchise.owner', 'assignedWorker.user']);

        return $this->applyForFieldWorkerOrder(
            identifyingColumn: 'parcel_order_id', identifyingId: $order->id,
            franchise: $order->franchise, worker: $order->assignedWorker,
            total: (float) ($order->price_final ?? $order->price_quoted),
            orderCode: $order->code, orderLabel: 'parcel order',
        );
    }

    /**
     * Phase 22.6 (Taxi) — the second real caller of the field-worker
     * commission split, which is what earned it a real shared private
     * helper (`applyForFieldWorkerOrder()` below) rather than a third
     * near-identical copy-paste of `applyForParcelOrder()`'s own body.
     * This is the exact "generalize once real repetition exists" moment
     * this codebase's own principle describes — the abstraction is
     * extracted FROM two real, working implementations, not designed
     * ahead of them.
     */
    public function applyForTaxiRide(TaxiRide $ride): Commission
    {
        $ride->loadMissing(['franchise.owner', 'assignedWorker.user']);

        return $this->applyForFieldWorkerOrder(
            identifyingColumn: 'taxi_ride_id', identifyingId: $ride->id,
            franchise: $ride->franchise, worker: $ride->assignedWorker,
            total: (float) ($ride->price_final ?? $ride->price_quoted),
            orderCode: $ride->code, orderLabel: 'taxi ride',
        );
    }

    /**
     * Phase 22.7 (Property Rental) — the fourth caller, and the first
     * whose "earner" is a `Provider` (the property's owner) rather than a
     * `FieldWorker`. Verified this still fits the shared helper below
     * before widening its type hint: both `FieldWorker` and `Provider`
     * expose the identical `->user` relation the helper actually uses —
     * nothing else about either type is read. Deliberately still NOT
     * routed through `applyForBooking()`'s own Plan-Entitlement rate-
     * override path — that's a Service/Plan-Engine-specific mechanism
     * (the Plan Engine is explicitly frozen/untouched elsewhere in this
     * codebase's history) with no evidence it should extend to Property
     * owners; inventing that extension here would be a real, unasked-for
     * scope expansion, not a safe generalization.
     */
    public function applyForPropertyReservation(PropertyReservation $reservation): Commission
    {
        $reservation->loadMissing(['franchise.owner', 'property.provider.user']);

        return $this->applyForFieldWorkerOrder(
            identifyingColumn: 'property_reservation_id', identifyingId: $reservation->id,
            franchise: $reservation->franchise, worker: $reservation->property?->provider,
            total: (float) ($reservation->price_final ?? $reservation->price_quoted),
            orderCode: $reservation->code, orderLabel: 'property reservation',
        );
    }

    /**
     * The real shared core behind applyForParcelOrder()/applyForTaxiRide()/
     * applyForPropertyReservation() — split calculation + Commission row +
     * wallet credits for a non-Booking order's "earner." Deliberately NOT
     * shared with applyForBooking() (Service) above — that method's
     * Plan-Entitlement commission-rate-override resolution has no
     * equivalent here, and threading a permanently-unused parameter
     * through this helper just to unify every method instead of the three
     * that already share this exact shape would be the "generalize
     * because names are similar" trap the mission's own instructions warn
     * against, not a genuine simplification. `$worker`'s type widened to
     * `FieldWorker|Provider` for Phase 22.7 — verified safe (see
     * applyForPropertyReservation()'s own docblock above) rather than
     * assumed.
     */
    private function applyForFieldWorkerOrder(
        string $identifyingColumn,
        int $identifyingId,
        \App\Models\Franchise $franchise,
        \App\Models\FieldWorker|\App\Models\Provider|null $worker,
        float $total,
        string $orderCode,
        string $orderLabel
    ): Commission {
        $existing = Commission::where($identifyingColumn, $identifyingId)->first();
        if ($existing) {
            return $existing;
        }

        $platformCommission = round($total * ($franchise->platform_fee_percent / 100), 2);

        $franchiseCommission = $franchise->commission_model === 'revenue_share'
            ? round($total * ($franchise->commission_value / 100), 2)
            : 0.0;

        $workerCommission = round($total - $platformCommission - $franchiseCommission, 2);

        return DB::transaction(function () use ($identifyingColumn, $identifyingId, $worker, $franchise, $workerCommission, $franchiseCommission, $platformCommission, $orderCode, $orderLabel) {
            $commission = Commission::create([
                $identifyingColumn => $identifyingId,
                'provider_commission' => $workerCommission, // reusing the same column Service uses for its "earner" share -- a FieldWorker's share here, not a Provider's
                'franchise_commission' => $franchiseCommission,
                'platform_commission' => $platformCommission,
            ]);

            if ($worker && $worker->user && $workerCommission > 0) {
                $this->walletService->credit(
                    $worker->user,
                    $workerCommission,
                    reason: "Earnings for {$orderLabel} {$orderCode}",
                    ref: "{$identifyingColumn}:{$identifyingId}:worker-earning"
                );
            }

            if ($franchise->owner_user_id && $franchiseCommission > 0) {
                $this->walletService->credit(
                    $franchise->owner,
                    $franchiseCommission,
                    reason: "Franchise revenue share for {$orderLabel} {$orderCode}",
                    ref: "{$identifyingColumn}:{$identifyingId}:franchise-earning"
                );
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

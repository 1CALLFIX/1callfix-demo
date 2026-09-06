<?php

namespace App\Actions;

use App\Models\Booking;
use App\Models\EntitlementBalance;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Services\Plans\UsageService;
use Illuminate\Support\Facades\DB;

/**
 * Explicit redemption of ONE named entitlement against a subscription —
 * the "use my AC Jet Pump Service visit now" / "use my Home Service
 * Credit for Plumbing" flow.
 *
 * Why this exists: the Plan Engine's only automatic Service consumer,
 * EntitlementService::resolveAndConsumeForBooking(),
 * is a booking-time *pricing* resolver — it groups candidates by
 * entitlement_type and picks a single discount winner. It has no way to
 * target one specific `quantity` entitlement among several on the same
 * plan, and no notion of a customer picking a category at redemption
 * time. A membership like Prime Silver is a book of distinct vouchers
 * (2× AC, 1× appliance, 1× home-service, 5× fee-waiver), each redeemed
 * deliberately. This Action is that deliberate redemption; it does NOT
 * touch the automatic pricing path.
 *
 * Contract:
 *   - subscription must be usable (active | grace_period)
 *   - the entitlement must belong to the subscription's plan
 *   - a 'current' EntitlementBalance must exist with remainingQuantity() >= $quantity
 *   - if the entitlement requiresCategoryChoice(), $category is required and
 *     must be one of its redeem_categories; otherwise $category must be null
 *   - decrements the balance and appends ONE `consume` row to usage_ledger
 *     (via UsageService, the sole ledger writer), stamping redeemed_category
 *     and created_by
 *
 * Reversible through the existing UsageService::reverse() — a redeemed
 * voucher given back on cancellation is the same 'reverse' row every other
 * consume event already uses.
 */
class RedeemEntitlementAction
{
    public function __construct(private UsageService $usageService) {}

    /**
     * @param  string|null  $category  required iff $entitlement->requiresCategoryChoice()
     * @param  int  $quantity  units to redeem (default 1)
     * @param  Booking|null  $booking  the job this redemption is applied to, if any
     * @param  int|null  $actingUserId  the admin/agent performing the redemption, for the audit row
     *
     * @throws \RuntimeException on any contract violation — nothing is written
     */
    public function execute(
        Subscription $subscription,
        PlanEntitlement $entitlement,
        ?string $category = null,
        int $quantity = 1,
        ?Booking $booking = null,
        ?int $actingUserId = null
    ): UsageLedger {
        if ($quantity < 1) {
            throw new \RuntimeException('Redemption quantity must be at least 1.');
        }

        if (! $subscription->isUsable()) {
            throw new \RuntimeException("Subscription is '{$subscription->status}', not active — nothing can be redeemed against it.");
        }

        if ($entitlement->plan_id !== $subscription->plan_id) {
            throw new \RuntimeException('That entitlement does not belong to this subscription\'s plan.');
        }

        $category = $this->resolveCategory($entitlement, $category);

        return DB::transaction(function () use ($subscription, $entitlement, $category, $quantity, $booking, $actingUserId) {
            $balance = EntitlementBalance::where('subscription_id', $subscription->id)
                ->where('plan_entitlement_id', $entitlement->id)
                ->where('status', 'current')
                ->lockForUpdate()
                ->first();

            if (! $balance) {
                throw new \RuntimeException('No current balance exists for that entitlement — it may be between periods or not yet activated.');
            }

            if ($balance->remainingQuantity() < $quantity) {
                throw new \RuntimeException(
                    "Not enough left on that entitlement: {$balance->remainingQuantity()} remaining, {$quantity} requested."
                );
            }

            $label = $entitlement->label ?: $entitlement->entitlement_type;
            $reason = "Redeemed {$quantity}× {$label}"
                .($category ? " ({$category})" : '')
                .($booking ? " for booking {$booking->code}" : '');

            return $this->usageService->consume(
                $balance,
                $quantity,
                0.0,
                $booking,
                false,
                null,
                $reason,
                $category,
                $actingUserId,
            );
        });
    }

    /**
     * Validates the category against the entitlement's own rules and
     * returns the value to store (null when the entitlement takes no
     * category).
     */
    private function resolveCategory(PlanEntitlement $entitlement, ?string $category): ?string
    {
        if (! $entitlement->requiresCategoryChoice()) {
            if ($category !== null) {
                throw new \RuntimeException('This entitlement does not take a category choice.');
            }

            return null;
        }

        $allowed = $entitlement->redeem_categories;

        if ($category === null || $category === '') {
            throw new \RuntimeException('This entitlement requires a category: '.implode(', ', $allowed).'.');
        }

        if (! in_array($category, $allowed, true)) {
            throw new \RuntimeException("'{$category}' is not a valid category for this entitlement (".implode(', ', $allowed).').');
        }

        return $category;
    }
}

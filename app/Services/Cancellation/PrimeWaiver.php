<?php

namespace App\Services\Cancellation;

use App\Models\Booking;
use App\Models\EntitlementBalance;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Models\User;
use App\Services\Plans\UsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REF 1CF-CANCEL-POLICY-001 — does the customer hold a Prime benefit that waives the en-route / visit-inspection
 * charge? Two sources, both Super-Admin controlled:
 *   1. a plan whose "waives cancellation visit charges" toggle is on (unlimited, not counted), or
 *   2. a usable `fee_waiver` ("Free Service Visit") entitlement with a unit left (one unit per waived visit).
 *
 * THUMB RULE — VISIT/INSPECTION CHARGE ONLY WHEN NO WORK IS DONE (CLAUDE.md): the charge, and therefore its waiver,
 * exists only on the no-work cancellation path. A booking that is carried out is never charged a visit charge, so the
 * waiver is never applied to it and a free-visit unit is never consumed by it. Consumption happens in
 * consumeForNoWorkVisit(), called when a cancelled booking is settled — nowhere on booking creation or completion.
 *
 * THUMB RULE — ONLINE PAYMENT ONLY FOR ALL BENEFITS: a cash booking receives no waiver of either kind.
 */
class PrimeWaiver
{
    public function covers(Booking $booking): bool
    {
        if (! $booking->customer_id || $booking->payment_method === 'cash') {
            return false;
        }

        return $this->coveredByPlanToggle($booking) || $this->freeVisitEntitlement($booking) !== null;
    }

    /**
     * Use up one Free Service Visit unit for a booking that was cancelled after the professional's verified arrival
     * with no work done. Idempotent (one ledger row per booking), and a no-op when the waiver came from the plan
     * toggle (not counted), the booking was paid in cash, work had started, or there is no unit left.
     */
    public function consumeForNoWorkVisit(Booking $booking): void
    {
        if ($booking->status !== 'cancelled'
            || $booking->arrival_verified_at === null
            || $booking->payment_method === 'cash'
            || $this->coveredByPlanToggle($booking)
            || $booking->statusHistory()->where('status', 'in_progress')->exists()
        ) {
            return;
        }

        try {
            DB::transaction(function () use ($booking) {
                $found = $this->freeVisitEntitlement($booking);
                if ($found === null) {
                    return;
                }

                [, $entitlement, $balance] = $found;

                if (UsageLedger::where('booking_id', $booking->id)->where('plan_entitlement_id', $entitlement->id)->where('event_type', 'consume')->exists()) {
                    return;
                }

                $locked = EntitlementBalance::lockForUpdate()->find($balance->id);
                if (! $locked || ($entitlement->quantity !== null && $locked->remainingQuantity() <= 0)) {
                    return;
                }

                $amount = app(InterimChargeCalculator::class)->standardVisitFee($booking, (float) ($booking->price_quoted ?? 0));

                app(UsageService::class)->consume(
                    $locked, 1, $amount, $booking, false, null,
                    'Free Service Visit — visit charge waived on a no-work cancellation'
                );
            });
        } catch (\Throwable $e) {
            Log::error("PrimeWaiver: could not record the Free Service Visit for cancelled booking [{$booking->id}]: ".$e->getMessage());
        }
    }

    private function coveredByPlanToggle(Booking $booking): bool
    {
        return Subscription::where('subscribable_type', User::class)
            ->where('subscribable_id', $booking->customer_id)
            ->whereIn('status', ['active', 'grace_period'])
            ->whereHas('plan', fn ($q) => $q->where('waives_cancellation_visit_charges', true))
            ->exists();
    }

    /** @return ?array{0: Subscription, 1: PlanEntitlement, 2: EntitlementBalance} the first usable Free Service Visit with a unit left */
    private function freeVisitEntitlement(Booking $booking): ?array
    {
        $subscriptions = Subscription::where('subscribable_type', User::class)
            ->where('subscribable_id', $booking->customer_id)
            ->whereIn('status', ['active', 'grace_period'])
            ->with(['plan.entitlements' => fn ($q) => $q->where('entitlement_type', 'fee_waiver')])
            ->get();

        foreach ($subscriptions as $subscription) {
            foreach ($subscription->plan->entitlements as $entitlement) {
                if (! $entitlement->isUsable()) {
                    continue;
                }

                $balance = EntitlementBalance::where('subscription_id', $subscription->id)
                    ->where('plan_entitlement_id', $entitlement->id)
                    ->where('status', 'current')
                    ->first();

                if ($balance && ($entitlement->quantity === null || $balance->remainingQuantity() > 0)) {
                    return [$subscription, $entitlement, $balance];
                }
            }
        }

        return null;
    }
}

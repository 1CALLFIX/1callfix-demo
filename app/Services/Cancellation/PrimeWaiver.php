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

/**
 * Prime waivers on the cancellation charges. Two separate questions:
 *
 *   covers()             the en-route charge — only a plan whose "waives cancellation visit charges" toggle is on
 *                        (unlimited, not counted).
 *   coversVisitCharge()  the visit / inspection charge — that toggle, OR a usable `fee_waiver` ("Free Service
 *                        Visit") entitlement with a unit left (one unit per waived visit).
 *
 * THUMB RULE — VISIT/INSPECTION CHARGE ONLY WHEN NO WORK IS DONE (CLAUDE.md): the charge, and therefore its waiver,
 * exists only on the no-work cancellation path. A booking that is carried out is never charged a visit charge, so a
 * waiver is never applied to it and a free-visit unit is never consumed by it.
 *
 * THUMB RULE — ONLINE PAYMENT ONLY FOR ALL BENEFITS: a cash booking receives no waiver of either kind.
 *
 * STRICT UNIT ACCOUNTING: a Free Service Visit waiver is never granted without a unit being consumed. While a
 * cancellation is being settled (beginSettlement … endSettlement, inside AdminCancelBookingAction's booking-locked
 * transaction) the waiver is only reported once the unit has actually been consumed — under a row lock on the member's
 * balance, in that same transaction — so two simultaneous no-work cancels with one unit left waive exactly one; the
 * other is charged. Outside a settlement (a quote) the answer is a read-only "would be waived if a unit remains".
 */
class PrimeWaiver
{
    /** @var array<int, bool> booking id => a settlement is in progress */
    private static array $settling = [];

    /** @var array<int, bool> booking id => a unit was consumed for it in the current settlement */
    private static array $claimed = [];

    /** The en-route charge waiver: the plan toggle only. */
    public function covers(Booking $booking): bool
    {
        if (! $booking->customer_id || $booking->payment_method === 'cash') {
            return false;
        }

        return $this->coveredByPlanToggle($booking);
    }

    /** The visit / inspection charge waiver (see class docblock for the strict unit rule). */
    public function coversVisitCharge(Booking $booking): bool
    {
        if (! $booking->customer_id || $booking->payment_method === 'cash') {
            return false;
        }

        if ($this->coveredByPlanToggle($booking)) {
            return true;
        }

        if (! isset(self::$settling[$booking->id])) {
            return $this->freeVisitEntitlement($booking) !== null; // quote: read-only
        }

        return self::$claimed[$booking->id] ??= $this->claimUnit($booking);
    }

    public function beginSettlement(int $bookingId): void
    {
        self::$settling[$bookingId] = true;
        unset(self::$claimed[$bookingId]);
    }

    public function endSettlement(int $bookingId): void
    {
        unset(self::$settling[$bookingId], self::$claimed[$bookingId]);
    }

    /**
     * Consume one free-visit unit for this booking, under a lock on the balance, in the caller's transaction.
     * Idempotent per booking. False (so the visit charge applies) when no unit can be consumed.
     */
    private function claimUnit(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking) {
            $found = $this->freeVisitEntitlement($booking);
            if ($found === null) {
                return false;
            }

            [, $entitlement, $balance] = $found;

            if (UsageLedger::where('booking_id', $booking->id)->where('plan_entitlement_id', $entitlement->id)->where('event_type', 'consume')->exists()) {
                return true; // already consumed for this very booking
            }

            $locked = EntitlementBalance::lockForUpdate()->find($balance->id);
            if (! $locked || ($entitlement->quantity !== null && $locked->remainingQuantity() <= 0)) {
                return false;
            }

            $amount = app(InterimChargeCalculator::class)->standardVisitFee($booking, (float) ($booking->price_quoted ?? 0));

            app(UsageService::class)->consume(
                $locked, 1, $amount, $booking, false, null,
                'Free Service Visit — visit charge waived on a no-work cancellation'
            );

            return true;
        });
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

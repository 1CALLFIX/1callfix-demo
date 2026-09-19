<?php

namespace App\Services\Plans;

use App\Exceptions\InsufficientEntitlementException;
use App\Models\Booking;
use App\Models\EntitlementBalance;
use App\Models\UsageLedger;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of usage_ledger. Every method appends a new row — never
 * edits or deletes an existing one (approved plan amendment 7: "never
 * mutate history destructively"). A 'reverse' row always points back at the
 * 'consume' row it reverses via related_usage_ledger_id.
 */
class UsageService
{
    /**
     * @param  string|null  $redeemedCategory  For a category-agnostic entitlement
     *         (PlanEntitlement::requiresCategoryChoice()), the single category the
     *         redeemer picked — recorded verbatim on the ledger row. Null for every
     *         other entitlement, exactly as before this parameter existed.
     * @param  int|null  $createdBy  The acting user for an explicit redemption
     *         (RedeemEntitlementAction). Null for the automatic booking-time
     *         consume path, which has no single acting admin.
     */
    public function consume(
        EntitlementBalance $balance,
        int $quantityDelta,
        float $monetaryDelta,
        ?Booking $booking = null,
        bool $wasOverage = false,
        ?float $overageCharged = null,
        ?string $reason = null,
        ?string $redeemedCategory = null,
        ?int $createdBy = null
    ): UsageLedger {
        return DB::transaction(function () use ($balance, $quantityDelta, $monetaryDelta, $booking, $wasOverage, $overageCharged, $reason, $redeemedCategory, $createdBy) {
            // The row lock is what serialises two concurrent redemptions of
            // the same balance — both the idempotency lookup and the
            // remaining-quantity check below must happen AFTER it is held.
            $balance = EntitlementBalance::lockForUpdate()->findOrFail($balance->id);

            // Idempotent per booking: a booking consumes a given entitlement
            // at most once. A repeated request / retried callback gets the
            // ORIGINAL ledger row back and nothing is written again. A row
            // that has since been reversed no longer counts, so a genuinely
            // new consumption can follow a cancellation.
            if ($booking !== null) {
                $existing = $this->activeConsumeFor($booking->id, $balance);
                if ($existing) {
                    return $existing;
                }
            }

            // Over-consumption guard, for quantity-limited entitlements only
            // (an unlimited entitlement has quantity NULL and no ceiling). A
            // zero-delta consume is an audit marker (commission-rate
            // adjustments) and never trips it.
            if (abs($quantityDelta) > 0 && ! $wasOverage && $balance->planEntitlement?->quantity !== null
                && $balance->remainingQuantity() < abs($quantityDelta)) {
                throw new InsufficientEntitlementException(
                    "Not enough left on that entitlement: {$balance->remainingQuantity()} remaining, ".abs($quantityDelta).' requested.'
                );
            }

            $balance->consumed_quantity += abs($quantityDelta);
            $balance->consumed_monetary_value += abs($monetaryDelta);
            $balance->save();

            return UsageLedger::create([
                'subscription_id' => $balance->subscription_id,
                'plan_entitlement_id' => $balance->plan_entitlement_id,
                'entitlement_balance_id' => $balance->id,
                'booking_id' => $booking?->id,
                'event_type' => 'consume',
                'quantity_delta' => -abs($quantityDelta),
                'monetary_delta' => -abs($monetaryDelta),
                'was_overage' => $wasOverage,
                'overage_amount_charged' => $overageCharged,
                'reason' => $reason,
                'redeemed_category' => $redeemedCategory,
                'created_by' => $createdBy,
            ]);
        });
    }

    /** The booking's live (not-yet-reversed) consume row for this balance's entitlement, if any. */
    public function activeConsumeFor(int $bookingId, EntitlementBalance $balance): ?UsageLedger
    {
        return UsageLedger::where('booking_id', $bookingId)
            ->where('subscription_id', $balance->subscription_id)
            ->where('plan_entitlement_id', $balance->plan_entitlement_id)
            ->where('event_type', 'consume')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('usage_ledger as r')
                    ->whereColumn('r.related_usage_ledger_id', 'usage_ledger.id')
                    ->where('r.event_type', 'reverse');
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Idempotent: if this consume event was already reversed, returns null
     * rather than reversing twice. Restores the balance, never creates
     * monetary value beyond what was originally consumed.
     */
    public function reverse(UsageLedger $consumeEvent, ?string $reason = null, ?int $adminUserId = null): ?UsageLedger
    {
        if ($consumeEvent->event_type !== 'consume') {
            throw new \InvalidArgumentException('Can only reverse a consume event.');
        }

        return DB::transaction(function () use ($consumeEvent, $reason, $adminUserId) {
            $alreadyReversed = UsageLedger::where('related_usage_ledger_id', $consumeEvent->id)
                ->where('event_type', 'reverse')
                ->lockForUpdate()
                ->exists();

            if ($alreadyReversed) {
                return null;
            }

            $balance = $consumeEvent->entitlement_balance_id
                ? EntitlementBalance::lockForUpdate()->find($consumeEvent->entitlement_balance_id)
                : null;

            if ($balance) {
                $balance->reversed_quantity += abs($consumeEvent->quantity_delta);
                $balance->reversed_monetary_value += abs($consumeEvent->monetary_delta);
                $balance->save();
            }

            return UsageLedger::create([
                'subscription_id' => $consumeEvent->subscription_id,
                'plan_entitlement_id' => $consumeEvent->plan_entitlement_id,
                'entitlement_balance_id' => $consumeEvent->entitlement_balance_id,
                'booking_id' => $consumeEvent->booking_id,
                'event_type' => 'reverse',
                'quantity_delta' => abs($consumeEvent->quantity_delta),
                'monetary_delta' => abs($consumeEvent->monetary_delta),
                'reason' => $reason ?? 'Reversed',
                'related_usage_ledger_id' => $consumeEvent->id,
                'created_by' => $adminUserId,
            ]);
        });
    }

    /** Manual admin correction — always ledger-backed. RBAC-gated by the caller, not here. */
    public function adjust(EntitlementBalance $balance, int $quantityDelta, float $monetaryDelta, string $reason, int $adminUserId): UsageLedger
    {
        return DB::transaction(function () use ($balance, $quantityDelta, $monetaryDelta, $reason, $adminUserId) {
            $balance = EntitlementBalance::lockForUpdate()->findOrFail($balance->id);

            if ($quantityDelta < 0) {
                $balance->consumed_quantity += abs($quantityDelta);
            } else {
                $balance->reversed_quantity += $quantityDelta;
            }
            if ($monetaryDelta < 0) {
                $balance->consumed_monetary_value += abs($monetaryDelta);
            } else {
                $balance->reversed_monetary_value += $monetaryDelta;
            }
            $balance->save();

            return UsageLedger::create([
                'subscription_id' => $balance->subscription_id,
                'plan_entitlement_id' => $balance->plan_entitlement_id,
                'entitlement_balance_id' => $balance->id,
                'event_type' => 'adjust',
                'quantity_delta' => $quantityDelta,
                'monetary_delta' => $monetaryDelta,
                'reason' => $reason,
                'created_by' => $adminUserId,
            ]);
        });
    }

    /** Forfeits a remaining balance at period close (rollover_policy = 'none') — an auditable event, never a silent drop. */
    public function expire(EntitlementBalance $balance, int $quantityDelta, float $monetaryDelta, string $reason): UsageLedger
    {
        return UsageLedger::create([
            'subscription_id' => $balance->subscription_id,
            'plan_entitlement_id' => $balance->plan_entitlement_id,
            'entitlement_balance_id' => $balance->id,
            'event_type' => 'expire',
            'quantity_delta' => -abs($quantityDelta),
            'monetary_delta' => -abs($monetaryDelta),
            'reason' => $reason,
        ]);
    }

    public function rollover(EntitlementBalance $fromBalance, EntitlementBalance $toBalance, int $quantityDelta, float $monetaryDelta): void
    {
        DB::transaction(function () use ($fromBalance, $toBalance, $quantityDelta, $monetaryDelta) {
            UsageLedger::create([
                'subscription_id' => $fromBalance->subscription_id,
                'plan_entitlement_id' => $fromBalance->plan_entitlement_id,
                'entitlement_balance_id' => $fromBalance->id,
                'event_type' => 'rollover_out',
                'quantity_delta' => -abs($quantityDelta),
                'monetary_delta' => -abs($monetaryDelta),
                'reason' => 'Carried to next period',
            ]);

            UsageLedger::create([
                'subscription_id' => $toBalance->subscription_id,
                'plan_entitlement_id' => $toBalance->plan_entitlement_id,
                'entitlement_balance_id' => $toBalance->id,
                'event_type' => 'rollover_in',
                'quantity_delta' => abs($quantityDelta),
                'monetary_delta' => abs($monetaryDelta),
                'reason' => 'Rolled over from previous period',
            ]);
        });
    }
}

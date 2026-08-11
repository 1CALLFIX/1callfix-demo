<?php

namespace App\Services\Plans;

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
    public function consume(
        EntitlementBalance $balance,
        int $quantityDelta,
        float $monetaryDelta,
        ?Booking $booking = null,
        bool $wasOverage = false,
        ?float $overageCharged = null,
        ?string $reason = null
    ): UsageLedger {
        return DB::transaction(function () use ($balance, $quantityDelta, $monetaryDelta, $booking, $wasOverage, $overageCharged, $reason) {
            $balance = EntitlementBalance::lockForUpdate()->findOrFail($balance->id);
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
            ]);
        });
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

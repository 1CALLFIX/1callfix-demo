<?php

namespace App\Services\Plans;

use App\Models\Booking;
use App\Models\EntitlementBalance;
use App\Models\PlanEntitlement;
use App\Models\Provider;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Models\User;
use App\Notifications\EntitlementNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Collection;

/**
 * "Which rate applies right now" — the zero-rejection resolver every real
 * consumer (CreateBookingAction, CompleteBookingAction via CommissionService,
 * AdminCancelBookingAction) calls into. Never blocks a transaction merely
 * because an entitlement is exhausted — see OverageService.
 */
class EntitlementService
{
    /** Only the Service module exists as a real consumer in Phase A (approved plan §6/amendment 14) — future modules pick their own trigger when built. */
    private const PRICING_TYPES = ['percentage_discount', 'fixed_discount', 'fee_waiver', 'member_price', 'quantity'];

    public function __construct(
        private PlanStackingResolver $stackingResolver,
        private OverageService $overageService,
        private UsageService $usageService,
    ) {
    }

    /**
     * Resolves AND consumes the customer-side pricing entitlement for a
     * just-created Service booking (consumption_trigger = booking_created,
     * per the approved plan's explicit §6 decision). Returns null when no
     * applicable/usable plan exists — caller keeps today's unmodified price.
     */
    public function resolveAndConsumeForBooking(User $customer, float $basePrice, Booking $booking): ?array
    {
        $candidates = $this->activeCustomerPricingEntitlements($customer);
        if ($candidates->isEmpty()) {
            return null;
        }

        $winner = null;
        foreach ($this->stackingResolver->groupByType($candidates) as $group) {
            $picked = $this->stackingResolver->resolveSingleType($group);
            // Highest raw benefit_value across types wins the single pricing
            // slot a booking can use — different discount TYPES can't both
            // apply to one price at once (that would be double-discounting),
            // unlike commission or truly independent entitlement types.
            if ($picked && (! $winner || $picked['benefit_value'] > $winner['benefit_value'])) {
                $winner = $picked;
            }
        }

        if (! $winner) {
            return null;
        }

        return $this->applyPricingEntitlement($winner, $basePrice, $booking);
    }

    /**
     * Provider-side commission_reduction/commission_override, resolved at
     * service_completed (approved plan §6). Returns null when no
     * applicable/usable plan exists.
     */
    public function resolveCommissionRateOverride(Provider $provider): ?array
    {
        if (! $provider->user_id) {
            return null;
        }

        $subscriptions = Subscription::where('subscribable_type', User::class)
            ->where('subscribable_id', $provider->user_id)
            ->whereIn('status', ['active', 'grace_period'])
            ->with(['plan.entitlements' => fn ($q) => $q->whereIn('entitlement_type', ['commission_reduction', 'commission_override'])])
            ->get();

        $candidates = collect();
        foreach ($subscriptions as $subscription) {
            foreach ($subscription->plan->entitlements as $entitlement) {
                if (! $entitlement->isUsable()) {
                    continue; // commission_override without an approval stays unusable — PlanEntitlement::isUsable() enforces this.
                }
                $candidates->push([
                    'subscription' => $subscription,
                    'plan' => $subscription->plan,
                    'entitlement' => $entitlement,
                    'benefit_value' => (float) $entitlement->percentage_value,
                ]);
            }
        }

        return $this->stackingResolver->resolveSingleType($candidates);
    }

    /** Called from AdminCancelBookingAction for pre-service cancellations only — the caller decides eligibility, this just finds and reverses. */
    public function reverseForCancelledBooking(Booking $booking): ?UsageLedger
    {
        $consumeEvent = UsageLedger::where('booking_id', $booking->id)
            ->where('event_type', 'consume')
            ->orderByDesc('id')
            ->first();

        if (! $consumeEvent) {
            return null;
        }

        $reversal = $this->usageService->reverse($consumeEvent, 'Booking cancelled before service began — entitlement restored');

        if ($reversal) {
            $subscription = $consumeEvent->subscription;
            $entitlement = $consumeEvent->planEntitlement;
            if ($subscription && $entitlement) {
                $this->notify($subscription, $entitlement, 'reversed');
            }
        }

        return $reversal;
    }

    private function notify(Subscription $subscription, PlanEntitlement $entitlement, string $event): void
    {
        $notifiable = $subscription->subscribable instanceof User ? $subscription->subscribable : null;
        if (! $notifiable) {
            return;
        }

        $channels = ChannelResolver::resolve([]);
        $notifiable->notify(new EntitlementNotification($event, $entitlement, $channels));
    }

    private function activeCustomerPricingEntitlements(User $customer): Collection
    {
        $subscriptions = Subscription::where('subscribable_type', User::class)
            ->where('subscribable_id', $customer->id)
            ->whereIn('status', ['active', 'grace_period'])
            ->with(['plan.entitlements' => fn ($q) => $q->whereIn('entitlement_type', self::PRICING_TYPES)
                ->where('consumption_trigger', 'booking_created')
                ->where(fn ($qq) => $qq->whereNull('module')->orWhere('module', 'service'))])
            ->get();

        $candidates = collect();
        foreach ($subscriptions as $subscription) {
            foreach ($subscription->plan->entitlements as $entitlement) {
                if (! $entitlement->isUsable()) {
                    continue;
                }
                $candidates->push([
                    'subscription' => $subscription,
                    'plan' => $subscription->plan,
                    'entitlement' => $entitlement,
                    'benefit_value' => $this->benefitValue($entitlement),
                ]);
            }
        }

        return $candidates;
    }

    private function benefitValue(PlanEntitlement $entitlement): float
    {
        return match ($entitlement->entitlement_type) {
            'percentage_discount' => (float) $entitlement->percentage_value,
            'fixed_discount' => (float) $entitlement->monetary_value,
            'fee_waiver' => PHP_FLOAT_MAX, // a full waiver always wins a benefit-value comparison against a partial discount
            'member_price' => (float) $entitlement->monetary_value,
            default => 0.0,
        };
    }

    private function applyPricingEntitlement(array $winner, float $basePrice, Booking $booking): array
    {
        $entitlement = $winner['entitlement'];
        $subscription = $winner['subscription'];

        $balance = $this->currentBalance($subscription, $entitlement);
        $isQuantityLimited = $entitlement->quantity !== null;
        $exhausted = $balance && $isQuantityLimited && $balance->remainingQuantity() <= 0;

        if ($exhausted) {
            $overage = $this->overageService->priceForExhaustedEntitlement($entitlement, $basePrice);

            UsageLedger::create([
                'subscription_id' => $subscription->id,
                'plan_entitlement_id' => $entitlement->id,
                'entitlement_balance_id' => $balance->id,
                'booking_id' => $booking->id,
                'event_type' => 'consume',
                'quantity_delta' => 0,
                'monetary_delta' => 0,
                'was_overage' => $overage['was_overage'],
                'overage_amount_charged' => $overage['overage_amount'],
                'reason' => 'Quota exhausted — '.($overage['was_overage'] ? 'overage rate applied' : 'standard price applied'),
            ]);

            $this->notify($subscription, $entitlement, 'exhausted');

            return [
                'plan_name' => $winner['plan']->name,
                'entitlement_type' => $entitlement->entitlement_type,
                'adjusted_price' => $overage['price'],
                'was_overage' => $overage['was_overage'],
            ];
        }

        $adjustedPrice = match ($entitlement->entitlement_type) {
            'percentage_discount' => round($basePrice * (1 - ((float) $entitlement->percentage_value / 100)), 2),
            'fixed_discount' => max(0, round($basePrice - (float) $entitlement->monetary_value, 2)),
            'fee_waiver' => 0.0,
            'member_price' => (float) $entitlement->monetary_value,
            'quantity' => $basePrice, // included in quota — price unchanged, just consumes a unit
            default => $basePrice,
        };

        $discountAmount = max(0, round($basePrice - $adjustedPrice, 2));

        if ($balance) {
            $this->usageService->consume($balance, 1, $discountAmount, $booking, false, null, 'Booking priced via '.$entitlement->entitlement_type);
        } else {
            // No balance row exists for this entitlement (shouldn't happen —
            // SubscriptionService::activate() creates one per entitlement —
            // but stay zero-rejection-safe: apply the price, still log an
            // auditable ledger row, just without a balance to decrement.
            UsageLedger::create([
                'subscription_id' => $subscription->id,
                'plan_entitlement_id' => $entitlement->id,
                'booking_id' => $booking->id,
                'event_type' => 'consume',
                'quantity_delta' => 0,
                'monetary_delta' => -$discountAmount,
                'reason' => 'Booking priced via '.$entitlement->entitlement_type.' (no balance row found)',
            ]);
        }

        $this->notify($subscription, $entitlement, 'consumed');

        return [
            'plan_name' => $winner['plan']->name,
            'entitlement_type' => $entitlement->entitlement_type,
            'adjusted_price' => $adjustedPrice,
            'was_overage' => false,
        ];
    }

    private function currentBalance(Subscription $subscription, PlanEntitlement $entitlement): ?EntitlementBalance
    {
        return EntitlementBalance::where('subscription_id', $subscription->id)
            ->where('plan_entitlement_id', $entitlement->id)
            ->where('status', 'current')
            ->first();
    }
}

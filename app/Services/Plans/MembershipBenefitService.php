<?php

namespace App\Services\Plans;

use App\Exceptions\InsufficientEntitlementException;
use App\Models\Address;
use App\Models\Booking;
use App\Models\EntitlementBalance;
use App\Models\PlanEntitlement;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\EntitlementNotification;
use App\Notifications\Support\ChannelResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applies DELIBERATELY-REDEEMED membership benefits (Prime-style vouchers) to
 * a Service booking — the counterpart to EntitlementService's legacy
 * percentage/fixed/fee discount rules, which it deliberately leaves alone.
 *
 * Nothing in here knows about "Prime Silver". A benefit is whatever a plan
 * entitlement says it is:
 *
 *   Plan → PlanEntitlement (redemption_effect, quantity, monetary_value)
 *        → PlanEntitlementTarget (existing catalog category / subcategory / service)
 *        → consumption (UsageService: transactional, idempotent, ledger-backed)
 *
 * Two effects exist:
 *   service_included  the covered service is included; its price is waived,
 *                     capped at the entitlement's advertised per-unit value.
 *   visit_fee_waiver  ONLY the visiting charge is waived: the entitlement's flat
 *                     value (Prime Silver: ₹199 per visit), or a service's own
 *                     lower visiting_charge. The service price, spare parts,
 *                     materials and out-of-scope work stay chargeable — a free
 *                     visit never waives more than that visiting charge.
 *
 * Only `price_quoted` is adjusted. Parts / materials / extra work are added
 * later through BookingExtraItem (ProposeExtraWorkAction) and are never touched
 * here, so they remain fully chargeable.
 *
 * Consumption happens once, at booking creation (consumption_trigger =
 * 'booking_created'), inside the booking's own transaction; a pre-service
 * cancellation reverses it through the existing
 * EntitlementService::reverseForCancelledBooking().
 */
class MembershipBenefitService
{
    public function __construct(private UsageService $usageService)
    {
    }

    /**
     * What benefit WOULD apply to this service for this customer at this
     * address — reads only, consumes nothing. Used by the booking wizard and
     * anywhere a quote must not burn an allowance.
     *
     * @return array<string, mixed>|null
     */
    public function preview(User $customer, Service $service, ?int $addressId, float $currentPrice): ?array
    {
        $pick = $this->pick($customer, $service, $addressId, $currentPrice);

        return $pick ? $this->describe($pick, $currentPrice) : null;
    }

    /**
     * Resolves AND consumes the best applicable benefit for a just-created
     * booking, returning the adjusted price (the caller writes it back).
     * Null means "no benefit applies" — the price stands unchanged. Never
     * throws for lack of entitlement: a quota exhausted between preview and
     * consume simply means the customer pays the normal price.
     *
     * @return array<string, mixed>|null
     */
    public function applyForBooking(User $customer, Service $service, Booking $booking, float $currentPrice): ?array
    {
        $pick = $this->pick($customer, $service, $booking->address_id, $currentPrice);
        if (! $pick) {
            return null;
        }

        // A repeated call for the same booking must not apply (or re-price) twice.
        if ($this->usageService->activeConsumeFor($booking->id, $pick['balance'])) {
            return null;
        }

        try {
            $ledger = $this->usageService->consume(
                $pick['balance'],
                1,
                $pick['waived'],
                $booking,
                false,
                null,
                $this->reason($pick, $booking),
                $pick['choice'],
            );
        } catch (InsufficientEntitlementException) {
            return null; // lost a race for the last unit — normal price applies
        }

        $balance = $pick['balance']->fresh();
        $this->notifyAfterCommit($pick['subscription'], $pick['entitlement'], $balance);

        return $this->describe($pick, $currentPrice) + ['ledger_id' => $ledger->id];
    }

    /**
     * Priority Based Service: does the customer hold a live membership with a
     * usable `priority` entitlement for this address? Only ever read by
     * ServiceMatchingJob to widen the offer batch — it grants no bypass of
     * provider eligibility, zone rules or location freshness.
     */
    public function customerHasPriority(User $customer, ?int $addressId): bool
    {
        foreach ($this->liveSubscriptions($customer, $addressId) as $subscription) {
            foreach ($subscription->plan->entitlements as $entitlement) {
                if ($entitlement->entitlement_type === 'priority' && $entitlement->isUsable()) {
                    return true;
                }
            }
        }

        return false;
    }

    // ------------------------------------------------------------ internals

    /**
     * Active/grace subscriptions of this customer that are inside their paid
     * period and — for an address-locked plan — registered to $addressId.
     *
     * @return Collection<int, Subscription>
     */
    private function liveSubscriptions(User $customer, ?int $addressId): Collection
    {
        $subscriptions = Subscription::where('subscribable_type', User::class)
            ->where('subscribable_id', $customer->id)
            ->whereIn('status', ['active', 'grace_period'])
            ->with(['plan.entitlements.targets'])
            ->orderBy('id')
            ->get();

        return $subscriptions->filter(
            fn (Subscription $s) => $s->plan && $this->withinPeriod($s) && $this->addressAllowed($s, $customer, $addressId)
        )->values();
    }

    /** A subscription past current_period_end is not redeemable even if the hourly cron has not flipped its status yet (grace excepted). */
    private function withinPeriod(Subscription $subscription): bool
    {
        if ($subscription->status === 'grace_period') {
            return true;
        }

        return $subscription->current_period_end === null || $subscription->current_period_end->isFuture();
    }

    /**
     * Address-locked plans are valid for ONE registered address. The booking's
     * address must be that saved address, and it must be the customer's own —
     * never another customer's.
     */
    private function addressAllowed(Subscription $subscription, User $customer, ?int $addressId): bool
    {
        if (! $subscription->plan->isAddressLocked()) {
            return true;
        }

        if ($subscription->registered_address_id === null || $addressId === null) {
            return false;
        }

        if ((int) $subscription->registered_address_id !== (int) $addressId) {
            return false;
        }

        return Address::where('id', $addressId)->where('user_id', $customer->id)->exists();
    }

    /**
     * The single best applicable benefit, or null. service_included outranks
     * visit_fee_waiver; ties break on larger waiver, then lower entitlement id
     * (deterministic — never an arbitrary pick).
     *
     * @return array<string, mixed>|null
     */
    private function pick(User $customer, Service $service, ?int $addressId, float $currentPrice): ?array
    {
        if ($currentPrice <= 0) {
            return null;
        }

        $candidates = collect();

        foreach ($this->liveSubscriptions($customer, $addressId) as $subscription) {
            foreach ($subscription->plan->entitlements as $entitlement) {
                if (! $this->eligibleEntitlement($entitlement, $service)) {
                    continue;
                }

                $covers = $entitlement->coversService($service);
                if ($covers === null) {
                    continue;
                }

                $balance = $this->currentBalance($subscription, $entitlement);
                if (! $balance || ($entitlement->quantity !== null && $balance->remainingQuantity() < 1)) {
                    continue;
                }

                $waived = $this->waiverFor($entitlement, $service, $currentPrice);
                if ($waived <= 0) {
                    continue;
                }

                $candidates->push([
                    'subscription' => $subscription,
                    'entitlement' => $entitlement,
                    'balance' => $balance,
                    'choice' => $covers['choice'],
                    'waived' => $waived,
                ]);
            }
        }

        return $candidates
            ->sortBy([
                fn ($a, $b) => ($a['entitlement']->redemption_effect === PlanEntitlement::EFFECT_SERVICE_INCLUDED ? 0 : 1)
                    <=> ($b['entitlement']->redemption_effect === PlanEntitlement::EFFECT_SERVICE_INCLUDED ? 0 : 1),
                fn ($a, $b) => $b['waived'] <=> $a['waived'],
                fn ($a, $b) => $a['entitlement']->id <=> $b['entitlement']->id,
            ])
            ->first();
    }

    private function eligibleEntitlement(PlanEntitlement $entitlement, Service $service): bool
    {
        return $entitlement->isRedemptionBased()
            && $entitlement->isUsable()
            && $entitlement->consumption_trigger === 'booking_created'
            && ($entitlement->module === null || $entitlement->module === 'service');
    }

    private function waiverFor(PlanEntitlement $entitlement, Service $service, float $currentPrice): float
    {
        $cap = $entitlement->monetary_value !== null && (float) $entitlement->monetary_value > 0
            ? (float) $entitlement->monetary_value
            : null;

        if ($entitlement->redemption_effect === PlanEntitlement::EFFECT_VISIT_FEE_WAIVER) {
            // The entitlement carries the flat visiting charge it waives (Prime Silver: ₹199
            // per visit). A service may carry a LOWER visiting charge of its own, in which
            // case only that lower amount is waived; a higher one never waives more than the
            // entitlement's flat value. Never more than the price, and never the service
            // itself — the work stays chargeable. With no flat value configured (legacy
            // entitlement) the service's own visiting_charge is the only source, as before.
            $serviceCharge = (float) ($service->visiting_charge ?? 0);

            $waiver = match (true) {
                $cap !== null && $serviceCharge > 0 => min($cap, $serviceCharge),
                $cap !== null => $cap,
                default => $serviceCharge,
            };

            return $waiver > 0 ? round(min($waiver, $currentPrice), 2) : 0.0;
        }

        // service_included — capped at the configured maximum benefit value when one is set
        // (AC Jet Pump ₹1,500 per service; a Home Service Credit may be set to ₹499). This is
        // a benefit against ONE service, never wallet or cash credit.
        return round($cap !== null ? min($cap, $currentPrice) : $currentPrice, 2);
    }

    private function currentBalance(Subscription $subscription, PlanEntitlement $entitlement): ?EntitlementBalance
    {
        return EntitlementBalance::where('subscription_id', $subscription->id)
            ->where('plan_entitlement_id', $entitlement->id)
            ->where('status', 'current')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function describe(array $pick, float $currentPrice): array
    {
        /** @var PlanEntitlement $entitlement */
        $entitlement = $pick['entitlement'];
        $remainingAfter = $entitlement->quantity !== null
            ? max(0, $pick['balance']->remainingQuantity() - 1)
            : null;

        return [
            'subscription_id' => $pick['subscription']->id,
            'plan_name' => $pick['subscription']->plan->name,
            'entitlement_id' => $entitlement->id,
            'entitlement' => $entitlement->displayName(),
            'effect' => $entitlement->redemption_effect,
            'choice' => $pick['choice'],
            'waived' => $pick['waived'],
            'original_price' => round($currentPrice, 2),
            'adjusted_price' => round(max(0, $currentPrice - $pick['waived']), 2),
            'remaining_after' => $remainingAfter,
        ];
    }

    private function reason(array $pick, Booking $booking): string
    {
        $entitlement = $pick['entitlement'];
        $verb = $entitlement->redemption_effect === PlanEntitlement::EFFECT_VISIT_FEE_WAIVER
            ? 'visiting charge waived'
            : 'service included';

        return "{$pick['subscription']->plan->name}: {$entitlement->displayName()} — {$verb}"
            .($pick['choice'] ? " ({$pick['choice']})" : '')
            ." for booking {$booking->code}";
    }

    /**
     * Membership notifications are sent once the booking transaction has
     * actually committed — a booking that rolls back (e.g. wallet
     * insufficient) must never tell the customer a benefit was used.
     */
    private function notifyAfterCommit(Subscription $subscription, PlanEntitlement $entitlement, EntitlementBalance $balance): void
    {
        DB::afterCommit(function () use ($subscription, $entitlement, $balance) {
            $customer = $subscription->subscribable;
            if (! $customer instanceof User) {
                return;
            }

            $channels = ChannelResolver::resolve([]);
            $customer->notify(new EntitlementNotification('consumed', $entitlement, $channels));

            if ($entitlement->quantity !== null && $balance->remainingQuantity() <= 0) {
                $customer->notify(new EntitlementNotification('exhausted', $entitlement, $channels));
            }
        });
    }
}

<?php

namespace App\Services\Plans;

use App\Contracts\PaymentGateway;
use App\Models\Address;
use App\Models\BusinessAccount;
use App\Models\EntitlementBalance;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Support\ChannelResolver;
use App\Notifications\SubscriptionStatusNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Full subscription lifecycle. Purchase reuses the EXACT gateway
 * order/webhook path WalletTopUpService generalized (payments.purpose =
 * 'plan_subscription') — no second payment system, no second gateway
 * integration (approved plan §13).
 */
class SubscriptionService
{
    public function __construct(
        private EligibilityService $eligibilityService,
        private PaymentGateway $gateway,
    ) {
    }

    /** A subscription in any of these states is "held": buying the same plan again would double-charge for it. */
    private const HELD_STATUSES = ['active', 'grace_period', 'past_due', 'paused'];

    /**
     * @param  Model  $actor  App\Models\User or App\Models\BusinessAccount
     * @param  string  $actingAs  'customer' | 'provider' | 'business_account'
     * @param  int|null  $registeredAddressId  the saved address an address-locked plan is registered to (required for such plans)
     * @throws \RuntimeException if the plan isn't purchasable, the actor isn't eligible, or they already hold it
     */
    public function initiateSubscribe(Model $actor, string $actingAs, Plan $plan, ?int $registeredAddressId = null): array
    {
        if (! $plan->is_active) {
            throw new \RuntimeException('This plan is not currently available.');
        }
        if (! $this->eligibilityService->canPurchase($actor, $actingAs, $plan)) {
            throw new \RuntimeException('You are not eligible for this plan.');
        }

        $registeredAddressId = $this->resolveRegisteredAddress($actor, $plan, $registeredAddressId);

        $subscription = DB::transaction(function () use ($actor, $plan, $registeredAddressId) {
            // Serialise concurrent purchases by the same subscriber: with no
            // existing rows there is nothing to row-lock, so lock the
            // subscriber itself. Two simultaneous "Buy" requests then run one
            // after the other and the second sees the first's subscription.
            $actor::query()->whereKey($actor->getKey())->lockForUpdate()->first();

            $existing = Subscription::where('subscribable_type', get_class($actor))
                ->where('subscribable_id', $actor->getKey())
                ->where('plan_id', $plan->id)
                ->whereIn('status', [...self::HELD_STATUSES, 'pending_payment', 'failed'])
                ->orderByDesc('id')
                ->get();

            if ($existing->contains(fn (Subscription $s) => in_array($s->status, self::HELD_STATUSES, true))) {
                throw new \RuntimeException('You already have this plan. Use renew instead of buying it again.');
            }

            // An abandoned checkout, or a purchase whose payment failed before it
            // ever activated, is RETRIED on the same row rather than piling up
            // another pending subscription.
            $reusable = $existing->first(
                fn (Subscription $s) => $s->status === 'pending_payment' || ($s->status === 'failed' && $s->starts_at === null)
            );

            if ($reusable) {
                $reusable->status = 'pending_payment';
                $reusable->registered_address_id = $registeredAddressId;
                $reusable->save();

                return $reusable;
            }

            return Subscription::create([
                'subscribable_type' => get_class($actor),
                'subscribable_id' => $actor->getKey(),
                'plan_id' => $plan->id,
                'registered_address_id' => $registeredAddressId,
                'status' => 'pending_payment',
                'auto_renew' => true,
            ]);
        });

        if ((float) $plan->price <= 0) {
            $this->activate($subscription);

            return ['subscription_id' => $subscription->id, 'requires_payment' => false];
        }

        return $this->createSubscriptionPaymentOrder($subscription, $plan, 'plan-'.$subscription->id.'-'.Str::random(6));
    }

    /**
     * An address-locked plan needs one of the buyer's OWN saved addresses;
     * every other plan ignores the argument entirely (provider packages and
     * business subscriptions are unaffected).
     */
    private function resolveRegisteredAddress(Model $actor, Plan $plan, ?int $addressId): ?int
    {
        if (! $plan->isAddressLocked()) {
            return null;
        }

        if (! $actor instanceof User) {
            throw new \RuntimeException('This plan is registered to a personal saved address.');
        }

        if ($addressId === null || ! Address::where('id', $addressId)->where('user_id', $actor->id)->exists()) {
            throw new \RuntimeException('Choose the saved address this membership is registered to.');
        }

        return $addressId;
    }

    /**
     * Retry for a subscription that is past_due / in grace / expired, or whose
     * checkout was abandoned or failed — POST /subscriptions/{id}/renew-now.
     * Re-enters the same order/webhook path a fresh purchase uses.
     */
    public function renewNow(Subscription $subscription): array
    {
        if (! in_array($subscription->status, ['past_due', 'grace_period', 'expired', 'pending_payment', 'failed'], true)) {
            throw new \RuntimeException("Cannot manually renew from status '{$subscription->status}'.");
        }

        $plan = $subscription->plan;
        $subscription->status = 'pending_payment';
        $subscription->save();

        if ((float) $plan->price <= 0) {
            $this->activate($subscription);

            return ['subscription_id' => $subscription->id, 'requires_payment' => false];
        }

        return $this->createSubscriptionPaymentOrder($subscription, $plan, 'plan-renew-'.$subscription->id.'-'.Str::random(6));
    }

    /** Called from PaymentController::handlePaymentCaptured() when purpose === 'plan_subscription'. Idempotent — a second webhook retry for an already-active subscription is a no-op. */
    public function activateAfterPayment(Payment $payment): void
    {
        if (! $payment->plan_subscription_id) {
            return;
        }

        $subscription = Subscription::find($payment->plan_subscription_id);
        if (! $subscription || $subscription->status !== 'pending_payment') {
            return;
        }

        $this->activate($subscription);
    }

    public function failPayment(Payment $payment): void
    {
        if (! $payment->plan_subscription_id) {
            return;
        }

        $subscription = Subscription::find($payment->plan_subscription_id);
        if (! $subscription || $subscription->status !== 'pending_payment') {
            return;
        }

        // A NEWER checkout for the same subscription may still be in flight (the
        // customer retried). Its capture must still be able to activate this row.
        // Only payments created AFTER this one count: an older still-pending order
        // is a stale abandoned attempt and must never keep a failed one open.
        $newerPending = Payment::where('plan_subscription_id', $subscription->id)
            ->where('status', 'pending')
            ->where('id', '>', $payment->id)
            ->exists();
        if ($newerPending) {
            return;
        }

        // A subscription that HAS been active before is a failed RENEWAL, not a
        // dead purchase: put it back where it was so the subscriber can retry
        // (renewNow) and the hourly cron still expires it if they never do. Only
        // a purchase that never activated becomes 'failed'.
        $wasActive = $subscription->starts_at !== null;
        $subscription->status = ! $wasActive
            ? 'failed'
            : ($subscription->expires_at !== null ? 'expired' : 'past_due');
        $subscription->save();

        $this->notify($subscription->fresh(), $wasActive ? 'renewal_failed' : 'failed');
    }

    public function activate(Subscription $subscription): Subscription
    {
        $subscription = DB::transaction(function () use ($subscription) {
            $subscription = Subscription::lockForUpdate()->findOrFail($subscription->id);
            $plan = $subscription->plan()->with('entitlements')->first();

            $start = now();
            $end = $plan->computePeriodEnd($start);

            // A renewal (grace-period / past-due / expired -> paid) starts a NEW
            // period. The old period's balances must be closed first, with an
            // auditable 'expire' event for whatever was left — otherwise two
            // 'current' balances would coexist and every lookup would draw from
            // the stale one. Nothing carries forward (Prime Silver: no carry-over).
            $this->closeCurrentBalances($subscription);

            $subscription->status = 'active';
            $subscription->starts_at = $subscription->starts_at ?? $start;
            $subscription->current_period_start = $start;
            $subscription->current_period_end = $end;
            $subscription->expires_at = null;
            $subscription->grace_period_ends_at = null;
            $subscription->expiry_reminder_sent_at = null;
            $subscription->save();

            foreach ($plan->entitlements as $entitlement) {
                EntitlementBalance::create([
                    'subscription_id' => $subscription->id,
                    'plan_entitlement_id' => $entitlement->id,
                    'period_start' => $start,
                    'period_end' => $end,
                    'granted_quantity' => $entitlement->quantity ?? 0,
                    'granted_monetary_value' => $entitlement->grantedMonetaryValue(),
                    'status' => 'current',
                ]);
            }

            return $subscription->fresh();
        });

        $this->notify($subscription, 'subscribed');

        return $subscription;
    }

    /** Closes every 'current' balance of the subscription, recording a forfeit event for any unused remainder. */
    private function closeCurrentBalances(Subscription $subscription): void
    {
        $usage = app(UsageService::class);

        $balances = EntitlementBalance::where('subscription_id', $subscription->id)
            ->where('status', 'current')
            ->lockForUpdate()
            ->get();

        foreach ($balances as $balance) {
            $remainingQty = max(0, $balance->remainingQuantity());
            $remainingVal = max(0, $balance->remainingMonetaryValue());

            if ($remainingQty > 0 || $remainingVal > 0) {
                $usage->expire($balance, $remainingQty, $remainingVal, 'Period closed at renewal — unused benefit forfeited');
            }

            $balance->status = 'closed';
            $balance->save();
        }
    }

    public function cancel(Subscription $subscription, string $reason): Subscription
    {
        if ($subscription->status === 'pending_payment') {
            throw new \RuntimeException('This subscription has not been paid for yet, so there is nothing to cancel.');
        }

        if (! in_array($subscription->status, self::HELD_STATUSES, true)) {
            throw new \RuntimeException("Cannot cancel a subscription that is '{$subscription->status}'.");
        }

        // Already cancelled: safe to repeat, and never a second notification.
        if ($subscription->cancelled_at !== null) {
            return $subscription;
        }

        // A marker, not an immediate terminal jump — stays usable through
        // current_period_end, RenewalService flips it to expired at the
        // boundary (approved plan §3).
        $subscription->auto_renew = false;
        $subscription->cancelled_at = now();
        $subscription->cancellation_reason = $reason;
        $subscription->save();

        $this->notify($subscription->fresh(), 'cancelled');

        return $subscription->fresh();
    }

    public function pause(Subscription $subscription): Subscription
    {
        if ($subscription->status !== 'active') {
            throw new \RuntimeException("Cannot pause from status '{$subscription->status}'.");
        }
        $subscription->status = 'paused';
        $subscription->save();

        return $subscription->fresh();
    }

    public function resume(Subscription $subscription): Subscription
    {
        if ($subscription->status !== 'paused') {
            throw new \RuntimeException("Cannot resume from status '{$subscription->status}'.");
        }
        $subscription->status = 'active';
        $subscription->save();

        return $subscription->fresh();
    }

    public function scheduleUpgrade(Subscription $subscription, Plan $newPlan): Subscription
    {
        return $this->scheduleChange($subscription, $newPlan, 'upgrade');
    }

    public function scheduleDowngrade(Subscription $subscription, Plan $newPlan): Subscription
    {
        return $this->scheduleChange($subscription, $newPlan, 'downgrade');
    }

    private function scheduleChange(Subscription $subscription, Plan $newPlan, string $type): Subscription
    {
        if (! $subscription->isUsable()) {
            throw new \RuntimeException('Subscription is not active.');
        }

        // The target plan is validated as strictly as a fresh purchase would be:
        // a subscriber must not be able to schedule a switch onto a plan they
        // could not buy, or one from a different family/actor type entirely.
        $current = $subscription->plan;

        if ($newPlan->id === $current->id) {
            throw new \RuntimeException('You are already on this plan.');
        }
        if (! $newPlan->is_active) {
            throw new \RuntimeException('That plan is not currently available.');
        }
        if ($newPlan->plan_family !== $current->plan_family || $newPlan->eligible_actor_type !== $current->eligible_actor_type) {
            throw new \RuntimeException('You can only switch to another plan of the same kind.');
        }
        if (! $this->eligibilityService->canPurchase($subscription->subscribable, $newPlan->eligible_actor_type, $newPlan)) {
            throw new \RuntimeException('You are not eligible for that plan.');
        }

        $subscription->pending_plan_id = $newPlan->id;
        $subscription->pending_change_type = $type;
        $subscription->pending_change_effective_at = $subscription->current_period_end;
        $subscription->save();

        return $subscription->fresh();
    }

    private function createSubscriptionPaymentOrder(Subscription $subscription, Plan $plan, string $receipt): array
    {
        // Plans are frequently global (no natural franchise/zone context to
        // scope this Setting lookup by), unlike booking/wallet payments --
        // same payment.online_enabled toggle, global default only.
        if (Setting::get('payment.online_enabled', '1') !== '1') {
            throw new \RuntimeException('Online payments are currently disabled.');
        }

        $order = $this->gateway->createRawOrder(
            (float) $plan->price,
            $receipt,
            ['subscription_id' => $subscription->id, 'purpose' => 'plan_subscription']
        );

        $payerUserId = $subscription->subscribable instanceof User
            ? $subscription->subscribable_id
            : ($subscription->subscribable instanceof BusinessAccount ? $subscription->subscribable->owner_user_id : null);

        $payment = Payment::create([
            'user_id' => $payerUserId,
            'purpose' => 'plan_subscription',
            'plan_subscription_id' => $subscription->id,
            'amount' => $plan->price,
            'gateway' => $this->gateway->identifier(),
            'gateway_order_id' => $order['razorpay_order_id'],
            'status' => 'pending',
        ]);

        return [
            'subscription_id' => $subscription->id,
            'requires_payment' => true,
            'payment_id' => $payment->id,
            'razorpay_order_id' => $order['razorpay_order_id'],
            'razorpay_key_id' => $order['key_id'],
            'amount' => $order['amount'],
            'currency' => $order['currency'],
        ];
    }

    private function notify(Subscription $subscription, string $event): void
    {
        $notifiable = $subscription->subscribable instanceof User
            ? $subscription->subscribable
            : $subscription->subscribable?->owner;

        if (! $notifiable) {
            return;
        }

        $channels = ChannelResolver::resolve([]);
        $notifiable->notify(new SubscriptionStatusNotification($event, $subscription, $channels));
    }
}

<?php

namespace App\Services\Plans;

use App\Contracts\PaymentGateway;
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

    /**
     * @param  Model  $actor  App\Models\User or App\Models\BusinessAccount
     * @param  string  $actingAs  'customer' | 'provider' | 'business_account'
     * @throws \RuntimeException if the plan isn't purchasable or the actor isn't eligible
     */
    public function initiateSubscribe(Model $actor, string $actingAs, Plan $plan): array
    {
        if (! $plan->is_active) {
            throw new \RuntimeException('This plan is not currently available.');
        }
        if (! $this->eligibilityService->canPurchase($actor, $actingAs, $plan)) {
            throw new \RuntimeException('You are not eligible for this plan.');
        }

        $subscription = Subscription::create([
            'subscribable_type' => get_class($actor),
            'subscribable_id' => $actor->getKey(),
            'plan_id' => $plan->id,
            'status' => 'pending_payment',
            'auto_renew' => true,
        ]);

        if ((float) $plan->price <= 0) {
            $this->activate($subscription);

            return ['subscription_id' => $subscription->id, 'requires_payment' => false];
        }

        return $this->createSubscriptionPaymentOrder($subscription, $plan, 'plan-'.$subscription->id.'-'.Str::random(6));
    }

    /** Manual retry for a past_due/expired subscription — POST /subscriptions/{id}/renew-now. Re-enters the same order/webhook path a fresh purchase uses. */
    public function renewNow(Subscription $subscription): array
    {
        if (! in_array($subscription->status, ['past_due', 'grace_period', 'expired'], true)) {
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

        $subscription->status = 'failed';
        $subscription->save();

        $this->notify($subscription->fresh(), 'failed');
    }

    public function activate(Subscription $subscription): Subscription
    {
        $subscription = DB::transaction(function () use ($subscription) {
            $subscription = Subscription::lockForUpdate()->findOrFail($subscription->id);
            $plan = $subscription->plan()->with('entitlements')->first();

            $start = now();
            $end = $plan->computePeriodEnd($start);

            $subscription->status = 'active';
            $subscription->starts_at = $subscription->starts_at ?? $start;
            $subscription->current_period_start = $start;
            $subscription->current_period_end = $end;
            $subscription->expires_at = null;
            $subscription->grace_period_ends_at = null;
            $subscription->save();

            foreach ($plan->entitlements as $entitlement) {
                EntitlementBalance::create([
                    'subscription_id' => $subscription->id,
                    'plan_entitlement_id' => $entitlement->id,
                    'period_start' => $start,
                    'period_end' => $end,
                    'granted_quantity' => $entitlement->quantity ?? 0,
                    'granted_monetary_value' => $entitlement->monetary_value ?? 0,
                    'status' => 'current',
                ]);
            }

            return $subscription->fresh();
        });

        $this->notify($subscription, 'subscribed');

        return $subscription;
    }

    public function cancel(Subscription $subscription, string $reason): Subscription
    {
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

<?php

namespace App\Livewire\Customer\Membership;

use App\Contracts\PaymentGateway;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Plans\MembershipPresenter;
use App\Services\Plans\SubscriptionService;
use App\Services\TimezoneResolver;
use Livewire\Component;

/**
 * The signed-in customer's own memberships: status, dates, remaining benefits,
 * usage history, renew and cancel.
 *
 * Read-only over the engine's own rows (subscriptions / entitlement_balances /
 * usage_ledger). Renew and cancel call SubscriptionService — the SAME methods
 * POST /api/subscriptions/{id}/renew-now and /cancel call — and every lookup is
 * scoped to auth()->user(), so another customer's subscription id resolves to
 * a 404, never their data.
 */
class Account extends Component
{
    public string $error = '';

    public string $notice = '';

    private const STATUS_LABELS = [
        'pending_payment' => 'Awaiting payment',
        'active' => 'Active',
        'grace_period' => 'Renewal due — benefits still available',
        'past_due' => 'Payment due',
        'paused' => 'Paused',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        'failed' => 'Payment failed',
    ];

    /** Statuses from which the customer can start (or retry) a payment. */
    private const PAYABLE = ['pending_payment', 'failed', 'past_due', 'grace_period', 'expired'];

    /** Statuses in which a cancellation makes sense. */
    private const CANCELLABLE = ['active', 'grace_period', 'past_due', 'paused'];

    /** Only ever the signed-in customer's own customer-membership subscription. */
    private function owned(int $id): Subscription
    {
        return Subscription::where('subscribable_type', User::class)
            ->where('subscribable_id', auth()->id())
            ->whereHas('plan', fn ($q) => $q->where('plan_family', 'customer_membership'))
            ->findOrFail($id);
    }

    public function renew(int $id, SubscriptionService $subscriptions, PaymentGateway $gateway): void
    {
        $this->reset('error', 'notice');
        $subscription = $this->owned($id);

        if ((float) $subscription->plan->price > 0 && ! $gateway->isConfigured()) {
            $this->error = 'Online payment is not available in this environment right now.';

            return;
        }

        try {
            $result = $subscriptions->renewNow($subscription);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        if ($result['requires_payment'] ?? false) {
            $this->dispatch('razorpay-open', order: $result);
            $this->notice = 'Complete the payment to continue your membership. It updates as soon as the payment is confirmed.';
        } else {
            $this->notice = 'Your membership is active.';
        }
    }

    public function cancel(int $id, SubscriptionService $subscriptions): void
    {
        $this->reset('error', 'notice');
        $subscription = $this->owned($id); // a foreign id is a 404 — never turned into a friendly message

        try {
            $subscriptions->cancel($subscription, 'Cancelled by customer');
            $this->notice = 'Your membership will not renew. Your benefits stay available until the current period ends.';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $presenter = app(MembershipPresenter::class);
        $tz = app(TimezoneResolver::class);

        $subscriptions = Subscription::where('subscribable_type', User::class)
            ->where('subscribable_id', auth()->id())
            ->whereHas('plan', fn ($q) => $q->where('plan_family', 'customer_membership'))
            ->with(['plan', 'registeredAddress'])
            ->latest('id')
            ->get()
            ->map(function (Subscription $s) use ($presenter, $tz) {
                $usage = $s->usageLedger()->with(['planEntitlement', 'booking:id,code'])->latest('id')->limit(50)->get();
                $balances = $presenter->currentBalances($s);

                // Which category a choose-one credit was spent on (Home Service Credit).
                $spentOn = $usage->where('event_type', 'consume')->whereNotNull('redeemed_category')
                    ->groupBy('plan_entitlement_id')
                    ->map(fn ($rows) => $rows->pluck('redeemed_category')->unique()->values()->all());

                return [
                    'model' => $s,
                    'status_label' => $s->cancelled_at && in_array($s->status, ['active', 'grace_period', 'past_due', 'paused'], true)
                        ? 'Cancelled — ends '.$tz->format($s->current_period_end, null, 'j M Y')
                        : (self::STATUS_LABELS[$s->status] ?? ucfirst($s->status)),
                    'activated' => $tz->format($s->starts_at, null, 'j M Y'),
                    'ends' => $tz->format($s->current_period_end, null, 'j M Y'),
                    'days_left' => $s->current_period_end && $s->isUsable() ? max(0, (int) now()->diffInDays($s->current_period_end, false)) : null,
                    'balances' => $balances,
                    'spent_on' => $spentOn,
                    'usage' => $usage->map(fn ($row) => $presenter->usage($row)),
                    'can_pay' => in_array($s->status, self::PAYABLE, true),
                    'can_cancel' => in_array($s->status, self::CANCELLABLE, true) && $s->cancelled_at === null,
                ];
            });

        return view('livewire.customer.membership.account', [
            'subscriptions' => $subscriptions,
            'hasPending' => $subscriptions->contains(fn ($row) => $row['model']->status === 'pending_payment'),
            'offer' => Plan::where('is_active', true)->where('plan_family', 'customer_membership')
                ->where('eligible_actor_type', 'customer')->where('scope_type', 'global')->orderBy('price')->first(),
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'gatewayConfigured' => app(PaymentGateway::class)->isConfigured(),
        ])->layout('components.layouts.customer', ['title' => 'My membership']);
    }
}

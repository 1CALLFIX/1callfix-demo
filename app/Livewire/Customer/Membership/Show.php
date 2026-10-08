<?php

namespace App\Livewire\Customer\Membership;

use App\Contracts\PaymentGateway;
use App\Models\Address;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Plans\MembershipPresenter;
use App\Services\Plans\SubscriptionService;
use Livewire\Component;

/**
 * Membership details + purchase for ONE customer plan (e.g. Prime Silver).
 *
 * Everything shown is read from the plan / plan_entitlements rows through
 * MembershipPresenter — no price, benefit or term is written into this view.
 * Purchase delegates to SubscriptionService::initiateSubscribe(), the SAME
 * service POST /api/plans/{plan}/subscribe calls: it creates the
 * pending_payment subscription and ONE Razorpay order, and the existing
 * /webhooks/razorpay endpoint activates it once Razorpay confirms capture.
 * Nothing on this screen — opening checkout included — activates anything.
 */
class Show extends Component
{
    public Plan $plan;

    public ?int $addressId = null;

    public string $error = '';

    public string $notice = '';

    public function mount(Plan $plan): void
    {
        // Only a real, live, customer-facing membership is ever reachable — a
        // future/draft plan (or a provider package) 404s exactly like a missing one.
        abort_unless(
            $plan->is_active
                && $plan->plan_family === 'customer_membership'
                && $plan->eligible_actor_type === 'customer'
                && $this->visibleToViewer($plan),
            404
        );

        $this->plan = $plan;

        $default = $this->addresses()->first();
        $this->addressId = $default?->id;
    }

    /** Same scope rule GET /api/plans applies: global, or the viewer's own franchise / zone. */
    private function visibleToViewer(Plan $plan): bool
    {
        if ($plan->scope_type === 'global') {
            return true;
        }

        $user = auth()->user();

        return match ($plan->scope_type) {
            'franchise' => $user && (int) $plan->scope_id === (int) $user->franchise_id,
            'zone' => $user && (int) $plan->scope_id === (int) $user->zone_id,
            default => false,
        };
    }

    private function addresses()
    {
        if (! auth()->check()) {
            return collect();
        }

        return Address::where('user_id', auth()->id())->orderByDesc('is_default')->latest()->get();
    }

    /** The viewer's own subscription to this plan that still matters (held, or awaiting payment). */
    private function mySubscription(): ?Subscription
    {
        if (! auth()->check()) {
            return null;
        }

        return Subscription::where('subscribable_type', User::class)
            ->where('subscribable_id', auth()->id())
            ->where('plan_id', $this->plan->id)
            ->whereIn('status', ['active', 'grace_period', 'past_due', 'paused', 'pending_payment'])
            ->orderByDesc('id')
            ->first();
    }

    public function purchase(SubscriptionService $subscriptions, PaymentGateway $gateway): void
    {
        $this->reset('error', 'notice');

        if (! auth()->check()) {
            $this->redirectRoute('customer.login', navigate: true);

            return;
        }

        $plan = Plan::find($this->plan->id);
        if (! $plan || ! $plan->is_active) {
            $this->error = 'This membership is not currently available.';

            return;
        }

        // Fail BEFORE creating a pending subscription if checkout cannot possibly open.
        if ((float) $plan->price > 0 && ! $gateway->isConfigured()) {
            $this->error = 'Online payment is not available in this environment right now.';

            return;
        }

        try {
            $result = $subscriptions->initiateSubscribe(auth()->user(), 'customer', $plan, $this->addressId);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        if (! ($result['requires_payment'] ?? false)) {
            $this->redirectRoute('customer.membership.account', navigate: true);

            return;
        }

        $this->dispatch('razorpay-open', order: $result);
        $this->notice = 'Complete the payment to start your membership. It activates as soon as the payment is confirmed.';
    }

    public function render()
    {
        $mine = $this->mySubscription();

        return view('livewire.customer.membership.show', [
            'card' => app(MembershipPresenter::class)->plan($this->plan),
            'presenter' => app(MembershipPresenter::class),
            'addresses' => $this->addresses(),
            'mine' => $mine,
            'holds' => $mine && $mine->status !== 'pending_payment',
            'currencySymbol' => Setting::get('locale.currency_symbol', '₹'),
            'gatewayConfigured' => app(PaymentGateway::class)->isConfigured(),
        ])->layout('components.layouts.customer', [
            'title' => $this->plan->name,
            'metaDescription' => 'Membership benefits, validity and terms for '.$this->plan->name.'.',
        ]);
    }
}

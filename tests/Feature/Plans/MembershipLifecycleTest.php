<?php

namespace Tests\Feature\Plans;

use App\Models\BusinessAccount;
use App\Models\EntitlementBalance;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Notifications\SubscriptionStatusNotification;
use App\Services\Plans\RenewalService;
use App\Services\Plans\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Support\PrimeSilverFixtures;
use Tests\TestCase;

/**
 * Purchase → verified payment → activation → renewal / expiry / cancellation,
 * against the real Razorpay webhook path (gateway faked, never reached) and the
 * real RenewalService. Also proves provider packages and business
 * subscriptions still behave exactly as before.
 */
class MembershipLifecycleTest extends TestCase
{
    use PrimeSilverFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A Prime Silver purchase that has been STARTED (pending_payment + a real order) but not paid. */
    private function startPurchase(): array
    {
        $this->fakeRazorpay();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $plan = $this->seedPrimeSilver();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan, $address->id);

        return compact('plan', 'customer', 'address', 'result') + ['subscription' => Subscription::findOrFail($result['subscription_id'])];
    }

    private function events(string $key)
    {
        return fn ($n) => $n instanceof SubscriptionStatusNotification && $n->eventKey() === $key;
    }

    // ================================================ purchase & verified payment

    public function test_starting_a_purchase_creates_a_pending_subscription_and_order_but_activates_nothing(): void
    {
        $p = $this->startPurchase();

        $this->assertTrue($p['result']['requires_payment']);
        $this->assertSame('pending_payment', $p['subscription']->status);
        $this->assertSame($p['address']->id, $p['subscription']->registered_address_id);
        $this->assertDatabaseHas('payments', ['plan_subscription_id' => $p['subscription']->id, 'purpose' => 'plan_subscription', 'status' => 'pending']);
        $this->assertSame(0, EntitlementBalance::where('subscription_id', $p['subscription']->id)->count(), 'Opening checkout allocates nothing.');
        $this->assertFalse($p['subscription']->isUsable());
    }

    public function test_a_verified_captured_payment_activates_and_allocates_the_five_entitlements(): void
    {
        Notification::fake();
        $p = $this->startPurchase();

        $this->postRazorpayWebhook($this->capturedWebhook($p['result']['razorpay_order_id']))->assertOk();

        $sub = $p['subscription']->fresh();
        $this->assertSame('active', $sub->status);
        $this->assertNotNull($sub->starts_at);
        $this->assertSame(5, EntitlementBalance::where('subscription_id', $sub->id)->where('status', 'current')->count());
        $this->assertDatabaseHas('payments', ['plan_subscription_id' => $sub->id, 'status' => 'captured']);
        Notification::assertSentTo($p['customer'], SubscriptionStatusNotification::class, $this->events('plan.subscribed'));
    }

    public function test_a_webhook_with_a_bad_signature_activates_nothing(): void
    {
        $p = $this->startPurchase();
        $payload = $this->capturedWebhook($p['result']['razorpay_order_id']);

        $this->postJson('/api/webhooks/razorpay', $payload, ['X-Razorpay-Signature' => 'not-a-valid-signature'])->assertStatus(400);

        $this->assertSame('pending_payment', $p['subscription']->fresh()->status);
    }

    public function test_a_duplicate_webhook_delivery_does_not_allocate_or_notify_twice(): void
    {
        Notification::fake();
        $p = $this->startPurchase();
        $payload = $this->capturedWebhook($p['result']['razorpay_order_id']);

        $this->postRazorpayWebhook($payload)->assertOk();
        $this->postRazorpayWebhook($payload)->assertOk();

        $this->assertSame(5, EntitlementBalance::where('subscription_id', $p['subscription']->id)->count(), 'Not 10.');
        Notification::assertSentToTimes($p['customer'], SubscriptionStatusNotification::class, 1);
    }

    public function test_a_failed_payment_leaves_it_unactivated_and_the_purchase_can_be_retried_on_the_same_row(): void
    {
        Notification::fake();
        $p = $this->startPurchase();

        $this->postRazorpayWebhook($this->failedWebhook($p['result']['razorpay_order_id']))->assertOk();
        $this->assertSame('failed', $p['subscription']->fresh()->status);
        Notification::assertSentTo($p['customer'], SubscriptionStatusNotification::class, $this->events('plan.failed'));

        // Retry: the same subscription row is reused, not a second one.
        $retry = app(SubscriptionService::class)->initiateSubscribe($p['customer'], 'customer', $p['plan'], $p['address']->id);
        $this->assertSame($p['subscription']->id, $retry['subscription_id']);
        $this->assertSame(1, Subscription::where('subscribable_id', $p['customer']->id)->count());

        $this->postRazorpayWebhook($this->capturedWebhook($retry['razorpay_order_id'], 'pay_retry'))->assertOk();
        $this->assertSame('active', $p['subscription']->fresh()->status);
    }

    public function test_a_late_failure_of_an_older_order_does_not_kill_a_newer_checkout(): void
    {
        $p = $this->startPurchase();
        $retry = app(SubscriptionService::class)->initiateSubscribe($p['customer'], 'customer', $p['plan'], $p['address']->id);

        $this->postRazorpayWebhook($this->failedWebhook($p['result']['razorpay_order_id']))->assertOk();
        $this->assertSame('pending_payment', $p['subscription']->fresh()->status, 'A newer checkout is still in flight.');

        $this->postRazorpayWebhook($this->capturedWebhook($retry['razorpay_order_id']))->assertOk();
        $this->assertSame('active', $p['subscription']->fresh()->status);
    }

    // ================================================ duplicate purchase protection

    public function test_buying_twice_while_pending_reuses_the_one_subscription(): void
    {
        $p = $this->startPurchase();

        $again = app(SubscriptionService::class)->initiateSubscribe($p['customer'], 'customer', $p['plan'], $p['address']->id);

        $this->assertSame($p['subscription']->id, $again['subscription_id']);
        $this->assertSame(1, Subscription::where('subscribable_id', $p['customer']->id)->count());
    }

    public function test_an_already_held_membership_cannot_be_bought_again(): void
    {
        $m = $this->primeMember();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already have this plan');
        try {
            app(SubscriptionService::class)->initiateSubscribe($m['customer'], 'customer', $m['plan'], $m['address']->id);
        } finally {
            $this->assertSame(1, Subscription::where('subscribable_id', $m['customer']->id)->count());
        }
    }

    public function test_a_paused_or_past_due_membership_also_blocks_a_second_purchase(): void
    {
        $m = $this->primeMember();

        foreach (['paused', 'past_due', 'grace_period'] as $status) {
            $m['subscription']->update(['status' => $status]);
            try {
                app(SubscriptionService::class)->initiateSubscribe($m['customer'], 'customer', $m['plan'], $m['address']->id);
                $this->fail("A {$status} membership must block a second purchase.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('already have', $e->getMessage());
            }
        }
        $this->assertSame(1, Subscription::where('subscribable_id', $m['customer']->id)->count());
    }

    public function test_an_expired_membership_can_be_bought_afresh(): void
    {
        $m = $this->primeMember();
        $m['subscription']->update(['status' => 'expired', 'expires_at' => now()]);

        $result = app(SubscriptionService::class)->initiateSubscribe($m['customer'], 'customer', $m['plan'], $m['address']->id);

        $this->assertNotSame($m['subscription']->id, $result['subscription_id']);
    }

    public function test_the_registered_address_is_required_and_must_be_the_buyers_own(): void
    {
        $this->fakeRazorpay();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $plan = $this->seedPrimeSilver();
        $customer = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $strangersAddress = $this->makeAddress($stranger, $franchise, $zone);
        $service = app(SubscriptionService::class);

        foreach ([null, $strangersAddress->id, 999999] as $bad) {
            try {
                $service->initiateSubscribe($customer, 'customer', $plan, $bad);
                $this->fail('An address-locked plan must not be bought without one of the buyer\'s own addresses.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('saved address', $e->getMessage());
            }
        }

        $this->assertSame(0, Subscription::count(), 'A rejected purchase leaves nothing behind.');
    }

    public function test_the_subscribe_api_takes_the_address_and_rejects_a_missing_one(): void
    {
        $this->fakeRazorpay();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $plan = $this->seedPrimeSilver();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/plans/{$plan->id}/subscribe", ['acting_as' => 'customer'])
            ->assertStatus(422);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/plans/{$plan->id}/subscribe", ['acting_as' => 'customer', 'address_id' => $address->id])
            ->assertOk()
            ->assertJsonPath('requires_payment', true);
    }

    // ============================================================ renewal / expiry

    public function test_a_lapsed_membership_goes_past_due_and_then_expires_with_balances_closed(): void
    {
        Notification::fake();
        $m = $this->primeMember();
        $m['subscription']->update(['current_period_end' => now()->subMinute()]);

        $first = app(RenewalService::class)->processDueSubscriptions();
        $this->assertSame(1, $first['moved_to_past_due'] ?? 0);
        $sub = $m['subscription']->fresh();
        $this->assertSame('past_due', $sub->status);
        $this->assertFalse($sub->isUsable(), 'Benefits are not available while payment is due (no grace configured).');
        Notification::assertSentTo($m['customer'], SubscriptionStatusNotification::class, $this->events('plan.renewal_failed'));

        $second = app(RenewalService::class)->processDueSubscriptions();
        $this->assertSame(1, $second['expired'] ?? 0);
        $this->assertSame('expired', $sub->fresh()->status);
        $this->assertSame(0, EntitlementBalance::where('subscription_id', $sub->id)->where('status', 'current')->count());
        Notification::assertSentTo($m['customer'], SubscriptionStatusNotification::class, $this->events('plan.expired'));
    }

    public function test_the_grace_period_keeps_benefits_available_until_its_deadline(): void
    {
        Setting::set('plan.grace_period_days', '7');
        $m = $this->primeMember();
        $m['subscription']->update(['current_period_end' => now()->subMinute()]);

        app(RenewalService::class)->processDueSubscriptions();
        $sub = $m['subscription']->fresh();
        $this->assertSame('grace_period', $sub->status);
        $this->assertTrue($sub->isUsable());
        $this->assertEquals(300, $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet'])->price_quoted, 'Benefits still apply during grace.');

        $outcome = app(RenewalService::class)->processDueSubscriptions();
        $this->assertSame(1, $outcome['still_in_grace'] ?? 0);

        $sub->update(['grace_period_ends_at' => now()->subMinute()]);
        app(RenewalService::class)->processDueSubscriptions();
        $this->assertSame('expired', $sub->fresh()->status);
    }

    public function test_a_paid_renewal_closes_the_old_period_and_starts_one_clean_period(): void
    {
        Setting::set('plan.grace_period_days', '7');
        Carbon::setTestNow('2026-03-15 09:00:00');
        $m = $this->primeMember();
        $sub = $m['subscription'];
        $activated = $sub->starts_at->copy();

        $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']); // AC remaining 1
        $sub->update(['current_period_end' => now()->subMinute()]);
        app(RenewalService::class)->processDueSubscriptions();
        $this->assertSame('grace_period', $sub->fresh()->status);

        // Renew during grace, then the verified capture arrives.
        Carbon::setTestNow('2027-02-20 10:00:00');
        $order = app(SubscriptionService::class)->renewNow($sub->fresh());
        $this->postRazorpayWebhook($this->capturedWebhook($order['razorpay_order_id'], 'pay_renew'))->assertOk();

        $sub = $sub->fresh();
        $this->assertSame('active', $sub->status);
        $this->assertTrue($sub->starts_at->equalTo($activated), 'The original activation date is kept.');
        $this->assertTrue($sub->current_period_end->equalTo(Carbon::parse('2028-01-20 10:00:00')), 'A fresh 11-month period.');

        $this->assertSame(5, EntitlementBalance::where('subscription_id', $sub->id)->where('status', 'current')->count(), 'Exactly one live balance per entitlement — never two.');
        $this->assertSame(5, EntitlementBalance::where('subscription_id', $sub->id)->where('status', 'closed')->count());
        $this->assertSame(2, $this->balanceOf($sub, 'Premium AC Jet Pump Service')->remainingQuantity(), 'Renewed AC benefit is a fresh 2 / 2 — nothing carried forward.');
        $acId = $sub->plan->entitlements()->where('label', 'Premium AC Jet Pump Service')->value('id');
        $this->assertSame(1, UsageLedger::where('subscription_id', $sub->id)->where('plan_entitlement_id', $acId)
            ->where('event_type', 'expire')->where('quantity_delta', -1)->count(), 'The forfeited AC unit is an audited expire row.');
    }

    public function test_a_failed_renewal_payment_returns_to_past_due_and_can_be_retried(): void
    {
        Setting::set('plan.grace_period_days', '7');
        $m = $this->primeMember();
        $sub = $m['subscription'];
        $sub->update(['current_period_end' => now()->subMinute()]);
        app(RenewalService::class)->processDueSubscriptions();

        $order = app(SubscriptionService::class)->renewNow($sub->fresh());
        $this->assertSame('pending_payment', $sub->fresh()->status);
        $this->postRazorpayWebhook($this->failedWebhook($order['razorpay_order_id']))->assertOk();

        $this->assertSame('past_due', $sub->fresh()->status, 'A failed renewal is NOT a dead subscription.');

        $retry = app(SubscriptionService::class)->renewNow($sub->fresh());
        $this->postRazorpayWebhook($this->capturedWebhook($retry['razorpay_order_id'], 'pay_retry'))->assertOk();
        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_a_failed_payment_on_an_expired_membership_returns_it_to_expired(): void
    {
        $m = $this->primeMember();
        $sub = $m['subscription'];
        $sub->update(['current_period_end' => now()->subMinute()]);
        app(RenewalService::class)->processDueSubscriptions();
        app(RenewalService::class)->processDueSubscriptions();
        $this->assertSame('expired', $sub->fresh()->status);

        $order = app(SubscriptionService::class)->renewNow($sub->fresh());
        $this->postRazorpayWebhook($this->failedWebhook($order['razorpay_order_id']))->assertOk();

        $this->assertSame('expired', $sub->fresh()->status);
        $this->assertFalse($sub->fresh()->isUsable());
    }

    public function test_a_cancelled_membership_expires_at_period_end_instead_of_renewing(): void
    {
        $m = $this->primeMember();
        app(SubscriptionService::class)->cancel($m['subscription'], 'no longer needed');
        $m['subscription']->update(['current_period_end' => now()->subMinute()]);

        app(RenewalService::class)->processDueSubscriptions();

        $this->assertSame('expired', $m['subscription']->fresh()->status);
    }

    // ====================================================== expiry reminder

    public function test_the_expiry_reminder_is_sent_once_and_only_for_customer_memberships(): void
    {
        $m = $this->primeMember();
        Notification::fake(); // discard the activation notification; count only what the run below sends
        $m['subscription']->update(['current_period_end' => now()->addDays(10)]);

        // A provider package inside the same window must NOT get a reminder.
        $providerUser = $this->makeCustomer();
        $package = Plan::create([
            'name' => 'Pro Package', 'slug' => 'pro-'.Str::random(6), 'plan_family' => 'provider_package',
            'scope_type' => 'global', 'eligible_actor_type' => 'provider', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        $packageSub = Subscription::findOrFail(app(SubscriptionService::class)->initiateSubscribe($providerUser, 'provider', $package)['subscription_id']);
        $packageSub->update(['current_period_end' => now()->addDays(10)]);

        $counts = app(RenewalService::class)->processDueSubscriptions();
        $this->assertSame(1, $counts['expiry_reminded'] ?? 0);
        Notification::assertSentTo($m['customer'], SubscriptionStatusNotification::class, $this->events('plan.expiry_reminder'));
        Notification::assertNotSentTo($providerUser, SubscriptionStatusNotification::class, $this->events('plan.expiry_reminder'));

        app(RenewalService::class)->processDueSubscriptions();
        $reminders = Notification::sent($m['customer'], SubscriptionStatusNotification::class)
            ->filter(fn ($n) => $n->eventKey() === 'plan.expiry_reminder');
        $this->assertCount(1, $reminders, 'Exactly one reminder per period.');
    }

    public function test_no_reminder_is_sent_when_expiry_is_far_away(): void
    {
        $m = $this->primeMember(); // 11 months out
        Notification::fake();

        $counts = app(RenewalService::class)->processDueSubscriptions();

        $this->assertArrayNotHasKey('expiry_reminded', $counts);
        Notification::assertNothingSentTo($m['customer']);
    }

    // ======================================================== cancellation guards

    public function test_cancel_is_guarded_and_repeating_it_neither_errors_nor_notifies_again(): void
    {
        $m = $this->primeMember();
        Notification::fake();
        $svc = app(SubscriptionService::class);

        $svc->cancel($m['subscription'], 'first');
        $svc->cancel($m['subscription']->fresh(), 'second');

        $sub = $m['subscription']->fresh();
        $this->assertFalse($sub->auto_renew);
        $this->assertSame('first', $sub->cancellation_reason);
        $this->assertSame('active', $sub->status, 'Stays usable until the period ends.');
        Notification::assertSentToTimes($m['customer'], SubscriptionStatusNotification::class, 1);

        // Benefits keep working until the period ends.
        $this->assertEquals(300, $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet'])->price_quoted);
    }

    public function test_cancel_is_refused_for_unpaid_and_already_ended_subscriptions(): void
    {
        $p = $this->startPurchase();
        $svc = app(SubscriptionService::class);

        try {
            $svc->cancel($p['subscription'], 'x');
            $this->fail('Nothing to cancel before payment.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not been paid', $e->getMessage());
        }

        foreach (['expired', 'failed', 'cancelled'] as $status) {
            $p['subscription']->update(['status' => $status]);
            try {
                $svc->cancel($p['subscription']->fresh(), 'x');
                $this->fail("A {$status} subscription must not be cancellable.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($status, $e->getMessage());
            }
        }
    }

    // ==================================================== upgrade / downgrade

    public function test_a_plan_change_target_is_validated_like_a_purchase(): void
    {
        $m = $this->primeMember();
        $svc = app(SubscriptionService::class);
        $sub = $m['subscription'];

        $make = fn (array $over) => Plan::create($over + [
            'name' => 'Other', 'slug' => 'other-'.Str::random(6), 'plan_family' => 'customer_membership',
            'scope_type' => 'global', 'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);

        $cases = [
            'the same plan' => $sub->plan,
            'an inactive plan' => $make(['is_active' => false]),
            'another family' => $make(['plan_family' => 'provider_package']),
            'another actor type' => $make(['eligible_actor_type' => 'provider']),
        ];
        foreach ($cases as $why => $target) {
            try {
                $svc->scheduleUpgrade($sub->fresh(), $target);
                $this->fail("Scheduling a change to {$why} must be refused.");
            } catch (\RuntimeException) {
                $this->assertNull($sub->fresh()->pending_plan_id, "Nothing was scheduled for {$why}.");
            }
        }

        $ok = $svc->scheduleUpgrade($sub->fresh(), $make([]));
        $this->assertNotNull($ok->pending_plan_id);
    }

    // ============================================ provider / business regression

    public function test_provider_packages_and_business_subscriptions_are_unaffected(): void
    {
        $svc = app(SubscriptionService::class);

        // Provider package: no address needed, activates immediately (free), balances granted.
        $provider = $this->makeCustomer();
        $package = Plan::create([
            'name' => 'Pro Package', 'slug' => 'pro-'.Str::random(6), 'plan_family' => 'provider_package',
            'scope_type' => 'global', 'eligible_actor_type' => 'provider', 'billing_cycle' => 'monthly',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        PlanEntitlement::create(['plan_id' => $package->id, 'entitlement_type' => 'priority', 'quantity' => 3,
            'usage_period' => 'monthly', 'consumption_trigger' => 'booking_created', 'rollover_policy' => 'none']);

        $result = $svc->initiateSubscribe($provider, 'provider', $package);
        $sub = Subscription::findOrFail($result['subscription_id']);
        $this->assertSame('active', $sub->status);
        $this->assertNull($sub->registered_address_id);
        $this->assertSame(3, EntitlementBalance::where('subscription_id', $sub->id)->value('granted_quantity'));

        // Duplicate guard applies to the same plan for the same subscriber.
        $this->expectException(\RuntimeException::class);
        try {
            $svc->initiateSubscribe($provider, 'provider', $package);
        } finally {
            // Business account subscription still works.
            $owner = $this->makeCustomer();
            $account = BusinessAccount::create(['owner_user_id' => $owner->id, 'name' => 'Acme', 'status' => 'active']);
            $biz = Plan::create([
                'name' => 'Biz', 'slug' => 'biz-'.Str::random(6), 'plan_family' => 'customer_membership',
                'scope_type' => 'global', 'eligible_actor_type' => 'business_account', 'billing_cycle' => 'monthly',
                'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
            ]);
            $bizResult = $svc->initiateSubscribe($account, 'business_account', $biz);
            $this->assertSame('active', Subscription::findOrFail($bizResult['subscription_id'])->status);
        }
    }
}

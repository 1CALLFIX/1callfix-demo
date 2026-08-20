<?php

namespace Tests\Feature\Payments;

use App\Livewire\Payments\Index as PaymentsIndex;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Row-level scope coverage for the new Payments admin screen, same
 * discipline as 977c240's RowLevelScopeAuthorizationTest: screen access
 * (payments.view) alone isn't enough -- a scoped admin must only see
 * payment rows within their own geography, reached via booking.* (booking
 * purpose) OR user.* (wallet_topup/plan_subscription purpose), scoped by
 * AuthorizationService::scopeQuery()'s array-of-candidate-columns form.
 */
class PaymentsScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;

    public function test_view_denied_without_permission(): void
    {
        $actor = $this->makeUserWithNoPermissions();

        Livewire::actingAs($actor)->test(PaymentsIndex::class)->assertForbidden();
    }

    public function test_booking_purpose_payment_is_scoped_to_the_actors_own_zone(): void
    {
        $mine = $this->makeBookingScenario('searching_provider');
        $other = $this->makeBookingScenario('searching_provider');
        $myPayment = Payment::create(['booking_id' => $mine['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $otherPayment = Payment::create(['booking_id' => $other['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $actor = $this->makeUserWithPermission('payments.view', 'zone', $mine['zone']->id);

        $ids = Livewire::actingAs($actor)->test(PaymentsIndex::class)
            ->viewData('payments')->pluck('id')->all();

        $this->assertContains($myPayment->id, $ids);
        $this->assertNotContains($otherPayment->id, $ids);
    }

    public function test_wallet_topup_purpose_payment_is_scoped_to_the_payers_own_zone(): void
    {
        $mine = $this->makeBookingScenario('searching_provider');
        $other = $this->makeBookingScenario('searching_provider');
        $mine['provider']->user->update(['zone_id' => $mine['zone']->id]);
        $other['provider']->user->update(['zone_id' => $other['zone']->id]);
        $myPayment = Payment::create(['user_id' => $mine['provider']->user_id, 'purpose' => 'wallet_topup', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $otherPayment = Payment::create(['user_id' => $other['provider']->user_id, 'purpose' => 'wallet_topup', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $actor = $this->makeUserWithPermission('payments.view', 'zone', $mine['zone']->id);

        $ids = Livewire::actingAs($actor)->test(PaymentsIndex::class)
            ->viewData('payments')->pluck('id')->all();

        $this->assertContains($myPayment->id, $ids);
        $this->assertNotContains($otherPayment->id, $ids);
    }

    /**
     * Admin Command Center mission (Finance audit) — the docblock above has
     * claimed plan_subscription purpose payments are covered via the same
     * user.* path as wallet_topup since this screen was built, but no test
     * ever exercised that specific purpose value directly (only
     * wallet_topup was). Verified real: SubscriptionService::
     * createSubscriptionPaymentOrder() populates Payment.user_id (the
     * resolved payer) for plan_subscription exactly like wallet_topup does
     * -- confirmed by inspection before writing this test, not assumed.
     */
    public function test_plan_subscription_purpose_payment_is_scoped_to_the_payers_own_zone(): void
    {
        $mine = $this->makeBookingScenario('searching_provider');
        $other = $this->makeBookingScenario('searching_provider');
        $mine['customer']->update(['zone_id' => $mine['zone']->id, 'franchise_id' => $mine['franchise']->id]);
        $other['customer']->update(['zone_id' => $other['zone']->id, 'franchise_id' => $other['franchise']->id]);
        $myPayment = Payment::create(['user_id' => $mine['customer']->id, 'purpose' => 'plan_subscription', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $otherPayment = Payment::create(['user_id' => $other['customer']->id, 'purpose' => 'plan_subscription', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $actor = $this->makeUserWithPermission('payments.view', 'zone', $mine['zone']->id);

        $ids = Livewire::actingAs($actor)->test(PaymentsIndex::class)
            ->viewData('payments')->pluck('id')->all();

        $this->assertContains($myPayment->id, $ids);
        $this->assertNotContains($otherPayment->id, $ids);
    }

    /**
     * 2026-08-17 hardening regression: before this fix, a marketplace_order
     * payment's franchise was unreachable through EITHER `booking.*` or
     * `user.*` (both null for this purpose), so scopeQuery()'s own fail-
     * closed behavior hid it from every zone-scoped grant, not just other
     * zones' rows. Proven pre-fix-fails: this test genuinely failed before
     * the scopeColumns() extension (the row was simply absent, same as the
     * cross-zone-exclusion assertion below looks for).
     */
    public function test_marketplace_order_purpose_payment_is_visible_and_scoped_to_the_actors_own_zone(): void
    {
        $mine = $this->makeMarketplaceOrderScenario('completed');
        $other = $this->makeMarketplaceOrderScenario('completed');
        $myPayment = Payment::create(['marketplace_order_id' => $mine['order']->id, 'purpose' => 'marketplace_order', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $otherPayment = Payment::create(['marketplace_order_id' => $other['order']->id, 'purpose' => 'marketplace_order', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $actor = $this->makeUserWithPermission('payments.view', 'zone', $mine['zone']->id);

        $ids = Livewire::actingAs($actor)->test(PaymentsIndex::class)
            ->viewData('payments')->pluck('id')->all();

        $this->assertContains($myPayment->id, $ids, 'A marketplace_order payment within the actors own zone must be visible.');
        $this->assertNotContains($otherPayment->id, $ids);
    }

    public function test_search_results_stay_scoped(): void
    {
        $mine = $this->makeBookingScenario('searching_provider');
        $other = $this->makeBookingScenario('searching_provider');
        Payment::create(['booking_id' => $mine['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        Payment::create(['booking_id' => $other['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $actor = $this->makeUserWithPermission('payments.view', 'zone', $mine['zone']->id);

        $results = Livewire::actingAs($actor)->test(PaymentsIndex::class)
            ->set('search', $other['booking']->code)
            ->viewData('payments');

        $this->assertSame(0, $results->total());
    }

    public function test_super_admin_sees_every_zones_payments(): void
    {
        $mine = $this->makeBookingScenario('searching_provider');
        $other = $this->makeBookingScenario('searching_provider');
        Payment::create(['booking_id' => $mine['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        Payment::create(['booking_id' => $other['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $admin = $this->makeSuperAdmin();

        $ids = Livewire::actingAs($admin)->test(PaymentsIndex::class)
            ->viewData('payments')->pluck('booking_id')->all();

        $this->assertContains($mine['booking']->id, $ids);
        $this->assertContains($other['booking']->id, $ids);
    }

    public function test_a_global_scoped_grant_sees_every_zones_payments(): void
    {
        $mine = $this->makeBookingScenario('searching_provider');
        $other = $this->makeBookingScenario('searching_provider');
        Payment::create(['booking_id' => $mine['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        Payment::create(['booking_id' => $other['booking']->id, 'purpose' => 'booking', 'amount' => 500, 'gateway' => 'razorpay', 'status' => 'captured']);
        $actor = $this->makeUserWithPermission('payments.view', 'global');

        $ids = Livewire::actingAs($actor)->test(PaymentsIndex::class)
            ->viewData('payments')->pluck('booking_id')->all();

        $this->assertContains($mine['booking']->id, $ids);
        $this->assertContains($other['booking']->id, $ids);
    }
}

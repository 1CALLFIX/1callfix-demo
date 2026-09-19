<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Account\Addresses;
use App\Livewire\Customer\Booking\Wizard;
use App\Livewire\Customer\Membership\Account;
use App\Livewire\Customer\Membership\Show;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageLedger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\PrimeSilverFixtures;
use Tests\TestCase;

/**
 * The customer-facing membership experience: the EXISTING homepage card now
 * leads to a real details page, purchase goes through the existing subscription
 * service, and "my membership" shows balances and history. Everything is read
 * from the plan / balance / ledger rows — nothing is hard-coded in the views.
 */
class MembershipPagesTest extends TestCase
{
    use PrimeSilverFixtures;
    use RefreshDatabase;

    // ============================================================== homepage

    public function test_the_existing_homepage_card_now_links_to_the_real_membership_page(): void
    {
        $plan = $this->seedPrimeSilver();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="membership-heading"'), 'Still exactly one membership section.');
        $this->assertSame(1, substr_count($html, $plan->name), 'Still exactly one Prime Silver card.');
        $this->assertStringContainsString('About membership', $html);
        $this->assertStringContainsString('1,999.00', $html);
        $this->assertStringContainsString('11 months', $html, 'The card shows the real validity, not the raw "custom" billing cycle.');
        $this->assertStringNotContainsString('/ custom', $html);
        $this->assertStringContainsString('href="'.route('customer.membership.show', $plan).'"', $html);
        $this->assertStringNotContainsString(route('customer.coming-soon', 'booking'), $html, 'The CTA no longer dead-ends on the booking placeholder.');
    }

    public function test_the_homepage_shows_no_membership_section_without_a_live_plan(): void
    {
        $this->get('/')->assertOk()->assertDontSee('About membership');
    }

    // ================================================================ details

    public function test_the_details_page_shows_price_validity_every_benefit_scope_exclusions_and_terms(): void
    {
        $plan = $this->seedPrimeSilver();

        $this->get(route('customer.membership.show', $plan))
            ->assertOk()
            ->assertSee($plan->name)
            ->assertSee('1,999.00')
            ->assertSee('valid for 11 months from activation')
            ->assertSee('Valid for your registered address only')
            // the five benefits
            ->assertSee('Premium AC Jet Pump Service')
            ->assertSee('Appliance General Service')
            ->assertSee('Home Service Credit')
            ->assertSee('Free Service Visit')
            ->assertSee('Priority-based service')
            ->assertSee('×2')->assertSee('×5')
            ->assertSee('₹1,500')->assertSee('₹3,000')
            // AC scope + exclusions
            ->assertSee('Jet Pump Cleaning')->assertSee('Drain Line Cleaning')
            ->assertSee('Gas Leak Rectification')->assertSee('Copper Pipe Replacement')
            // appliance
            ->assertSee('Geyser Minor Service')->assertSee('Motor Replacement')
            // electrical / plumbing / carpenter scope + exclusions
            ->assertSee('MCB Replacement')->assertSee('Rewiring Works')
            ->assertSee('Tap Replacement')->assertSee('Water Tank Cleaning')
            ->assertSee('Drawer Repair')->assertSee('Plywood / Board Replacement')
            // policy + terms + priority explanation
            ->assertSee('Spare parts and materials are chargeable on every use')
            ->assertSee('does NOT guarantee immediate service')
            ->assertSee('Out-of-scope AC work is chargeable.')
            ->assertSee('Benefits are non-transferable.');
    }

    public function test_a_guest_sees_a_login_prompt_not_a_buy_button(): void
    {
        $plan = $this->seedPrimeSilver();

        $this->get(route('customer.membership.show', $plan))
            ->assertSee('Log in to purchase')
            ->assertDontSee('wire:click="purchase"', false);
    }

    public function test_only_a_live_customer_membership_is_reachable(): void
    {
        $this->seedPrimeSilver();
        $mk = fn (array $over) => Plan::create($over + [
            'name' => 'Hidden', 'slug' => 'hidden-'.Str::random(6), 'plan_family' => 'customer_membership',
            'scope_type' => 'global', 'eligible_actor_type' => 'customer', 'billing_cycle' => 'monthly',
            'price' => 100, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);

        $this->get(route('customer.membership.show', $mk(['is_active' => false])))->assertNotFound();
        $this->get(route('customer.membership.show', $mk(['plan_family' => 'provider_package', 'eligible_actor_type' => 'provider'])))->assertNotFound();
        $this->get('/membership/no-such-plan')->assertNotFound();
    }

    public function test_a_franchise_scoped_plan_is_hidden_from_customers_of_other_franchises(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $plan = Plan::create([
            'name' => 'Local', 'slug' => 'local-'.Str::random(6), 'plan_family' => 'customer_membership',
            'scope_type' => 'franchise', 'scope_id' => $franchise->id, 'eligible_actor_type' => 'customer',
            'billing_cycle' => 'monthly', 'price' => 100, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);

        $this->get(route('customer.membership.show', $plan))->assertNotFound();
    }

    // =============================================================== purchase

    public function test_purchase_opens_checkout_and_creates_a_pending_subscription_that_is_not_active(): void
    {
        $this->fakeRazorpay();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $plan = $this->seedPrimeSilver();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        Livewire::actingAs($customer)->test(Show::class, ['plan' => $plan])
            ->set('addressId', $address->id)
            ->call('purchase')
            ->assertDispatched('razorpay-open')
            ->assertSet('error', '');

        $sub = Subscription::where('subscribable_id', $customer->id)->firstOrFail();
        $this->assertSame('pending_payment', $sub->status, 'Opening checkout never activates a membership.');
        $this->assertSame($address->id, $sub->registered_address_id);
        $this->assertDatabaseHas('payments', ['plan_subscription_id' => $sub->id, 'status' => 'pending']);
    }

    public function test_purchase_by_a_guest_goes_to_login(): void
    {
        $plan = $this->seedPrimeSilver();

        Livewire::test(Show::class, ['plan' => $plan])->call('purchase')->assertRedirect(route('customer.login'));

        $this->assertSame(0, Subscription::count());
    }

    public function test_purchase_is_refused_before_creating_anything_when_no_gateway_is_configured(): void
    {
        config(['services.razorpay.key_id' => null, 'services.razorpay.key_secret' => null]);
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $plan = $this->seedPrimeSilver();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);

        Livewire::actingAs($customer)->test(Show::class, ['plan' => $plan])
            ->set('addressId', $address->id)
            ->call('purchase')
            ->assertSee('not available');

        $this->assertSame(0, Subscription::count(), 'No orphan pending subscription.');
    }

    public function test_purchase_needs_a_saved_address_and_cannot_use_someone_elses(): void
    {
        $this->fakeRazorpay();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $plan = $this->seedPrimeSilver();
        $customer = $this->makeCustomer();
        $stranger = $this->makeCustomer();
        $strangersAddress = $this->makeAddress($stranger, $franchise, $zone);

        Livewire::actingAs($customer)->test(Show::class, ['plan' => $plan])
            ->assertSee('Add an address')
            ->set('addressId', $strangersAddress->id)
            ->call('purchase')
            ->assertSee('Choose the saved address');

        $this->assertSame(0, Subscription::count());
    }

    public function test_a_member_sees_view_my_membership_instead_of_a_second_buy_button(): void
    {
        $m = $this->primeMember();

        Livewire::actingAs($m['customer'])->test(Show::class, ['plan' => $m['plan']])
            ->assertSee('You already have this membership')
            ->assertSee('View my membership')
            ->assertDontSee('Buy for');
    }

    // ================================================= my membership (account)

    public function test_the_account_page_needs_login_and_links_to_it(): void
    {
        $this->get(route('customer.membership.account'))->assertRedirect(route('customer.login'));

        $this->actingAs($this->makeCustomer())->get(route('customer.account'))
            ->assertOk()
            ->assertSee(route('customer.membership.account'))
            ->assertDontSee('Not yet available');
    }

    public function test_my_membership_shows_status_dates_and_full_balances(): void
    {
        $m = $this->primeMember();

        $html = Livewire::actingAs($m['customer'])->test(Account::class)
            ->assertSee($m['plan']->name)
            ->assertSee('Active')
            ->assertSee('11 months')
            ->assertSee($m['address']->label)
            ->assertSee('Premium AC Jet Pump Service')->assertSee('2 / 2')
            ->assertSee('Appliance General Service')->assertSee('1 / 1')
            ->assertSee('Home Service Credit')
            ->assertSee('Free Service Visit')->assertSee('5 / 5')
            ->assertSee('Priority-based service')->assertSee('Included')
            ->assertSee('Choose one: Electrical / Plumbing / Carpenter')
            ->assertSee('No benefits used yet')
            ->assertSee('Cancel membership')
            ->html();

        $this->assertStringContainsString($m['subscription']->starts_at->format('j M Y'), $html);
        $this->assertStringContainsString($m['subscription']->current_period_end->format('j M Y'), $html);
    }

    public function test_my_membership_reflects_usage_and_the_chosen_home_credit_category(): void
    {
        $m = $this->primeMember();
        $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);
        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['plumbing']);

        Livewire::actingAs($m['customer'])->test(Account::class)
            ->assertSee('1 / 2')                               // AC: one of two used
            ->assertSee('0 / 1')                               // Home Service Credit spent
            ->assertSee('Used for: Plumbing')                  // and on which category
            ->assertSee('used')                                // history rows
            ->assertSee('(Plumbing)')
            ->assertSee($booking->code)
            ->assertDontSee('No benefits used yet');
    }

    public function test_a_membership_awaiting_payment_says_so_and_polls(): void
    {
        $this->fakeRazorpay();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $plan = $this->seedPrimeSilver();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        app(\App\Services\Plans\SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan, $address->id);

        Livewire::actingAs($customer)->test(Account::class)
            ->assertSee('Awaiting payment')
            ->assertSee('waiting for your payment to be confirmed')
            ->assertSee('Complete payment')
            ->assertSeeHtml('wire:poll.5s');
    }

    public function test_the_customer_can_cancel_and_the_membership_stays_usable_until_period_end(): void
    {
        $m = $this->primeMember();

        Livewire::actingAs($m['customer'])->test(Account::class)
            ->call('cancel', $m['subscription']->id)
            ->assertSee('will not renew')
            ->assertSee('Cancelled — ends');

        $sub = $m['subscription']->fresh();
        $this->assertFalse($sub->auto_renew);
        $this->assertSame('active', $sub->status);
    }

    public function test_a_lapsed_membership_can_start_a_renewal_payment(): void
    {
        $m = $this->primeMember();
        $m['subscription']->update(['status' => 'expired', 'expires_at' => now()]);

        Livewire::actingAs($m['customer'])->test(Account::class)
            ->assertSee('Renew membership')
            ->call('renew', $m['subscription']->id)
            ->assertDispatched('razorpay-open');

        $this->assertSame('pending_payment', $m['subscription']->fresh()->status);
    }

    public function test_another_customers_subscription_id_is_a_404_never_their_data(): void
    {
        $m = $this->primeMember();
        $stranger = $this->makeCustomer();

        // The stranger's own page lists none of the member's subscription (the plan is
        // only offered as something to buy, never shown as something they hold).
        Livewire::actingAs($stranger)->test(Account::class)
            ->assertDontSeeHtml('data-subscription="'.$m['subscription']->id.'"')
            ->assertDontSee('Cancel membership')
            ->assertDontSee('2 / 2')
            ->assertSee('have a membership yet');

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($stranger)->test(Account::class)->call('cancel', $m['subscription']->id);
    }

    // ===================================================== booking wizard preview

    public function test_the_booking_wizard_previews_the_benefit_without_consuming_it(): void
    {
        $m = $this->primeMember();

        Livewire::actingAs($m['customer'])->test(Wizard::class, ['service' => $m['catalog']['ac_jet']])
            ->assertSee($m['plan']->name)
            ->assertSee('Premium AC Jet Pump Service')
            ->assertSee('service included')
            ->assertSee('Spare parts, materials and out-of-scope work are extra');

        $this->assertSame(0, UsageLedger::where('subscription_id', $m['subscription']->id)->count(), 'Viewing a quote never consumes.');
    }

    public function test_the_wizard_shows_no_preview_for_out_of_scope_work_or_non_members(): void
    {
        $m = $this->primeMember();

        Livewire::actingAs($m['customer'])->test(Wizard::class, ['service' => $m['catalog']['painting']])
            ->assertDontSee('service included')->assertDontSee('visiting charge waived');

        $stranger = $this->makeCustomer();
        $this->makeAddress($stranger, $m['franchise'], $m['zone']);
        Livewire::actingAs($stranger)->test(Wizard::class, ['service' => $m['catalog']['ac_jet']])
            ->assertDontSee('service included');
    }

    // ================================================== address delete protection

    public function test_the_registered_address_cannot_be_deleted_while_a_membership_is_registered_to_it(): void
    {
        $m = $this->primeMember();

        Livewire::actingAs($m['customer'])->test(Addresses::class)
            ->call('delete', $m['address']->id)
            ->assertSee('membership is registered to this address');
        $this->assertDatabaseHas('addresses', ['id' => $m['address']->id]);

        $this->actingAs($m['customer'], 'sanctum')
            ->deleteJson("/api/addresses/{$m['address']->id}")
            ->assertStatus(409);
        $this->assertDatabaseHas('addresses', ['id' => $m['address']->id]);
    }
}

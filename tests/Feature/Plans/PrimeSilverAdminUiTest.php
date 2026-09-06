<?php

namespace Tests\Feature\Plans;

use App\Livewire\Plans\Manage as PlansManage;
use App\Livewire\Subscriptions\Index as SubscriptionsIndex;
use App\Models\EntitlementBalance;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\UsageLedger;
use App\Services\Plans\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * The admin surfaces added for Prime Silver: the Plans form now captures
 * description / metadata / entitlement label / redeem categories, and the
 * Subscriptions screen can deliberately redeem one entitlement unit
 * (distinct from the existing "adjust" correction tool).
 */
class PrimeSilverAdminUiTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    public function test_plan_form_persists_description_and_metadata_json(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(PlansManage::class)
            ->set('name', 'Prime Silver Test')
            ->set('description', 'Valid at the registered address only.')
            ->set('metadataJson', '{"address_locked": true, "spare_parts_chargeable": true}')
            ->set('planFamily', 'customer_membership')
            ->set('eligibleActorType', 'customer')
            ->set('billingCycle', 'custom')
            ->set('customCycleDays', 334)
            ->set('price', '1999')
            ->call('save')
            ->assertHasNoErrors();

        $plan = Plan::where('name', 'Prime Silver Test')->firstOrFail();
        $this->assertSame('Valid at the registered address only.', $plan->description);
        $this->assertSame(['address_locked' => true, 'spare_parts_chargeable' => true], $plan->metadata);
        $this->assertSame(334, $plan->custom_cycle_days);
    }

    public function test_plan_form_rejects_non_object_metadata(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(PlansManage::class)
            ->set('name', 'Bad Metadata Plan')
            ->set('metadataJson', '[1, 2, 3]')
            ->set('eligibleActorType', 'customer')
            ->set('billingCycle', 'annual')
            ->set('price', '0')
            ->call('save');

        $this->assertDatabaseMissing('plans', ['name' => 'Bad Metadata Plan']);
    }

    public function test_entitlement_form_persists_label_and_redeem_categories(): void
    {
        $admin = $this->makeSuperAdmin();
        $plan = Plan::create([
            'name' => 'Plan With Entitlements', 'slug' => 'plan-with-ent-'.uniqid(),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'annual',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);

        Livewire::actingAs($admin)->test(PlansManage::class)
            ->call('expand', $plan->id)
            ->set('entType', 'quantity')
            ->set('entModule', 'service')
            ->set('entLabel', 'Home Service Credit')
            ->set('entRedeemCategories', 'electrical, plumbing, carpenter')
            ->set('entQuantity', 1)
            ->set('entUsagePeriod', 'monthly')
            ->set('entConsumptionTrigger', 'service_completed')
            ->set('entRolloverPolicy', 'none')
            ->call('addEntitlement')
            ->assertHasNoErrors();

        $ent = PlanEntitlement::where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame('Home Service Credit', $ent->label);
        $this->assertSame(['electrical', 'plumbing', 'carpenter'], $ent->redeem_categories);
        $this->assertTrue($ent->requiresCategoryChoice());
    }

    public function test_subscriptions_screen_redeems_one_entitlement_unit_through_the_action(): void
    {
        $admin = $this->makeSuperAdmin();

        $plan = Plan::create([
            'name' => 'Redeemable Plan', 'slug' => 'redeemable-'.uniqid(),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'annual',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        $ent = PlanEntitlement::create([
            'plan_id' => $plan->id, 'entitlement_type' => 'quantity', 'module' => 'service',
            'label' => 'Premium AC Jet Pump Service', 'quantity' => 2,
            'usage_period' => 'monthly', 'consumption_trigger' => 'service_completed', 'rollover_policy' => 'none',
        ]);

        $customer = $this->makeCustomer();
        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $subscription = Subscription::find($result['subscription_id']);
        $balance = EntitlementBalance::where('subscription_id', $subscription->id)->firstOrFail();

        $this->assertSame(2, $balance->remainingQuantity());

        Livewire::actingAs($admin)->test(SubscriptionsIndex::class)
            ->call('startRedeem', $balance->id)
            ->call('confirmRedeem')
            ->assertHasNoErrors();

        $this->assertSame(1, $balance->fresh()->remainingQuantity());
        $this->assertDatabaseHas('usage_ledger', [
            'entitlement_balance_id' => $balance->id,
            'event_type' => 'consume',
            'quantity_delta' => -1,
            'created_by' => $admin->id,
        ]);
    }

    public function test_redeem_button_flashes_the_actions_error_when_category_missing(): void
    {
        $admin = $this->makeSuperAdmin();

        $plan = Plan::create([
            'name' => 'Category Plan', 'slug' => 'category-'.uniqid(),
            'plan_family' => 'customer_membership', 'scope_type' => 'global',
            'eligible_actor_type' => 'customer', 'billing_cycle' => 'annual',
            'price' => 0, 'stacking_strategy' => 'exclusive', 'is_active' => true,
        ]);
        $ent = PlanEntitlement::create([
            'plan_id' => $plan->id, 'entitlement_type' => 'quantity', 'module' => 'service',
            'label' => 'Home Service Credit', 'quantity' => 1, 'redeem_categories' => ['electrical', 'plumbing', 'carpenter'],
            'usage_period' => 'monthly', 'consumption_trigger' => 'service_completed', 'rollover_policy' => 'none',
        ]);

        $customer = $this->makeCustomer();
        $result = app(SubscriptionService::class)->initiateSubscribe($customer, 'customer', $plan);
        $balance = EntitlementBalance::where('subscription_id', $result['subscription_id'])->firstOrFail();

        $before = UsageLedger::count();

        Livewire::actingAs($admin)->test(SubscriptionsIndex::class)
            ->call('startRedeem', $balance->id)
            ->call('confirmRedeem')
            ->assertSet('flashType', 'error');

        $this->assertSame($before, UsageLedger::count(), 'No ledger row on a refused redemption.');
        $this->assertSame(1, $balance->fresh()->remainingQuantity());
    }
}

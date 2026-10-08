<?php

namespace Tests\Feature\Plans;

use App\Jobs\ServiceMatchingJob;
use App\Livewire\Plans\Manage as PlansManage;
use App\Livewire\Services\Manage as ServicesManage;
use App\Livewire\Subscriptions\Index as SubscriptionsIndex;
use App\Models\Booking;
use App\Models\EntitlementBalance;
use App\Models\PlanEntitlement;
use App\Models\PlanEntitlementTarget;
use App\Models\UsageLedger;
use App\Services\DispatchService;
use App\Services\Plans\PlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\PrimeSilverFixtures;
use Tests\TestCase;

/**
 * The customer API payloads the audit found too thin, the admin screens'
 * safety rails (history can never be destroyed), authorization on every
 * mutation, and Priority Based Service in dispatch.
 */
class MembershipApiAdminSecurityTest extends TestCase
{
    use PrimeSilverFixtures;
    use RbacTestHelpers;
    use RefreshDatabase;

    private const AC = 'Premium AC Jet Pump Service';
    private const VISITS = '5 Free Cancellations (visit charge waived when no work is done)';
    private const CREDIT = 'Home Service Credit';

    // ==================================================================== API

    public function test_the_plans_api_returns_validity_terms_and_every_benefit_with_its_scope(): void
    {
        $m = $this->primeMember();

        $plan = collect($this->actingAs($m['customer'], 'sanctum')
            ->getJson('/api/plans?acting_as=customer')->assertOk()->json('plans'))
            ->firstWhere('slug', '1callfix-prime-silver');

        $this->assertSame('11 months', $plan['validity_label']);
        $this->assertTrue($plan['address_locked']);
        $this->assertCount(13, $plan['terms']);
        $this->assertCount(5, $plan['entitlements']);

        $ac = collect($plan['entitlements'])->firstWhere('label', self::AC);
        $this->assertSame(2, $ac['quantity']);
        $this->assertSame('service_included', $ac['redemption_effect']);
        $this->assertSame(self::AC, $ac['name']);
        $this->assertContains('Jet Pump Cleaning', $ac['includes']);
        $this->assertNotEmpty($ac['eligible'], 'The catalog services / categories the benefit covers.');

        $credit = collect($plan['entitlements'])->firstWhere('label', self::CREDIT);
        $this->assertSame(['electrical', 'plumbing', 'carpenter'], $credit['choices']);
    }

    public function test_the_entitlements_api_lets_a_client_tell_the_benefits_apart_and_keeps_the_original_keys(): void
    {
        $m = $this->primeMember();
        $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);

        $rows = collect($this->actingAs($m['customer'], 'sanctum')
            ->getJson("/api/subscriptions/{$m['subscription']->id}/entitlements")->assertOk()->json('entitlements'));

        $this->assertCount(5, $rows);
        $this->assertCount(5, $rows->pluck('name')->unique(), 'Three `quantity` benefits are distinguishable by name.');

        $ac = $rows->firstWhere('name', self::AC);
        $this->assertSame(1, $ac['remaining']);
        $this->assertSame(2, $ac['total']);
        $this->assertSame(1, $ac['used']);
        $this->assertTrue($ac['limited']);

        // Backward compatibility: the four original keys are unchanged.
        foreach (['entitlement_type', 'remaining_quantity', 'remaining_monetary_value', 'period_end'] as $key) {
            $this->assertArrayHasKey($key, $ac);
        }
        $this->assertSame(1, $ac['remaining_quantity']);

        $priority = $rows->firstWhere('entitlement_type', 'priority');
        $this->assertFalse($priority['limited']);
        $this->assertNull($priority['remaining']);
    }

    public function test_the_usage_api_names_the_benefit_the_category_and_the_booking(): void
    {
        $m = $this->primeMember();
        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['carpenter']);

        $usage = collect($this->actingAs($m['customer'], 'sanctum')
            ->getJson("/api/subscriptions/{$m['subscription']->id}/usage")->assertOk()->json('usage'))
            ->firstWhere('event_type', 'consume');

        $this->assertSame(self::CREDIT, $usage['entitlement_name']);
        $this->assertSame('carpenter', $usage['redeemed_category']);
        $this->assertSame($booking->code, $usage['booking_code']);
    }

    public function test_mine_includes_the_registered_address(): void
    {
        $m = $this->primeMember();

        $this->actingAs($m['customer'], 'sanctum')->getJson('/api/subscriptions/mine')
            ->assertOk()
            ->assertJsonPath('subscriptions.0.registered_address.id', $m['address']->id);
    }

    public function test_a_stranger_cannot_read_renew_change_or_cancel_another_customers_membership(): void
    {
        $m = $this->primeMember();
        $stranger = $this->makeCustomer();
        $id = $m['subscription']->id;

        foreach ([
            ['getJson', "/api/subscriptions/{$id}/entitlements", []],
            ['getJson', "/api/subscriptions/{$id}/usage", []],
            ['postJson', "/api/subscriptions/{$id}/cancel", []],
            ['postJson', "/api/subscriptions/{$id}/renew-now", []],
            ['postJson', "/api/subscriptions/{$id}/upgrade", ['plan_id' => $m['plan']->id]],
            ['postJson', "/api/subscriptions/{$id}/downgrade", ['plan_id' => $m['plan']->id]],
        ] as [$verb, $url, $body]) {
            $this->actingAs($stranger, 'sanctum')->{$verb}($url, $body)->assertStatus(403);
        }

        $this->actingAs($stranger, 'sanctum')->getJson('/api/subscriptions/mine')->assertOk()->assertJsonCount(0, 'subscriptions');
        $this->assertSame('active', $m['subscription']->fresh()->status);
        $this->assertTrue($m['subscription']->fresh()->auto_renew);
    }

    // =========================================== admin: plans & entitlements

    public function test_admin_can_edit_a_plans_price_and_validity_and_it_only_affects_future_periods(): void
    {
        $m = $this->primeMember();
        $admin = $this->makeSuperAdmin();
        $grantedBefore = $this->balanceOf($m['subscription'], self::AC)->granted_quantity;

        Livewire::actingAs($admin)->test(PlansManage::class)
            ->call('startEditPlan', $m['plan']->id)
            ->assertSet('editValidityMonths', 11)
            ->set('editPrice', '2199')
            ->set('editValidityMonths', 12)
            ->call('updatePlan')
            ->assertHasNoErrors();

        $plan = $m['plan']->fresh();
        $this->assertSame('2199.00', (string) $plan->price);
        $this->assertSame(12, $plan->validity_months);
        $this->assertSame($grantedBefore, $this->balanceOf($m['subscription'], self::AC)->granted_quantity, 'Existing balances are untouched.');
        $this->assertSame('active', $m['subscription']->fresh()->status);
    }

    public function test_a_plans_family_and_actor_type_are_locked_once_it_has_subscribers(): void
    {
        $m = $this->primeMember();

        $this->expectException(\RuntimeException::class);
        app(PlanService::class)->update($m['plan'], ['plan_family' => 'provider_package']);
    }

    public function test_an_entitlement_with_usage_history_can_never_be_deleted(): void
    {
        $m = $this->primeMember();
        $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);
        $ac = $m['plan']->entitlements->firstWhere('label', self::AC);
        $ledgerBefore = UsageLedger::count();

        // Guard at the model, so no caller can bypass it…
        try {
            $ac->delete();
            $this->fail('An entitlement with history must not be deletable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        // …and the admin screen reports it instead of crashing.
        Livewire::actingAs($this->makeSuperAdmin())->test(PlansManage::class)
            ->call('deleteEntitlement', $ac->id)
            ->assertSee('cannot be deleted');

        $this->assertDatabaseHas('plan_entitlements', ['id' => $ac->id]);
        $this->assertSame($ledgerBefore, UsageLedger::count(), 'Ledger history is intact.');
        $this->assertSame(1, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());
    }

    public function test_an_entitlement_without_history_can_still_be_deleted_with_its_targets(): void
    {
        $plan = $this->seedPrimeSilver();
        $this->mapPrimeTargets($plan, $this->makePrimeCatalog());
        $visits = $plan->entitlements->firstWhere('label', '5 Free Cancellations (visit charge waived when no work is done)');
        $this->assertGreaterThan(0, $visits->targets()->count());

        app(PlanService::class)->deleteEntitlement($visits);

        $this->assertDatabaseMissing('plan_entitlements', ['id' => $visits->id]);
        $this->assertSame(0, PlanEntitlementTarget::where('plan_entitlement_id', $visits->id)->count());
    }

    public function test_editing_an_entitlement_keeps_history_and_freezes_its_type(): void
    {
        $m = $this->primeMember();
        $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);
        $ac = $m['plan']->entitlements->firstWhere('label', self::AC);
        $admin = $this->makeSuperAdmin();

        $component = Livewire::actingAs($admin)->test(PlansManage::class)
            ->call('expand', $m['plan']->id)
            ->call('startEditEntitlement', $ac->id)
            ->assertSet('entLabel', self::AC)
            ->assertSet('entEffect', 'service_included');

        // A wording change is fine and keeps every balance and ledger row.
        $component->set('entDescription', 'Updated wording')->call('updateEntitlement')->assertHasNoErrors();
        $this->assertSame('Updated wording', $ac->fresh()->description);
        $this->assertSame(1, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());

        // Its type is frozen once it has history.
        $component->call('startEditEntitlement', $ac->id)->set('entType', 'percentage_discount')->call('updateEntitlement')
            ->assertSee('cannot be changed');
        $this->assertSame('quantity', $ac->fresh()->entitlement_type);
    }

    public function test_a_redeemable_benefit_must_use_the_booking_created_trigger(): void
    {
        $plan = $this->seedPrimeSilver();

        Livewire::actingAs($this->makeSuperAdmin())->test(PlansManage::class)
            ->call('expand', $plan->id)
            ->set('entType', 'quantity')
            ->set('entLabel', 'Bad trigger')
            ->set('entEffect', 'service_included')
            ->set('entConsumptionTrigger', 'service_completed')
            ->call('addEntitlement')
            ->assertSee('Booking created');

        $this->assertDatabaseMissing('plan_entitlements', ['label' => 'Bad trigger']);
    }

    public function test_admin_maps_an_entitlement_to_real_catalog_items_and_can_remove_them(): void
    {
        $plan = $this->seedPrimeSilver();
        $catalog = $this->makePrimeCatalog();
        $credit = $plan->entitlements->firstWhere('label', self::CREDIT);
        $component = Livewire::actingAs($this->makeSuperAdmin())->test(PlansManage::class)
            ->call('expand', $plan->id)
            ->call('toggleTargets', $credit->id);

        // A choose-one benefit needs the choice on each covered target.
        $component->set('tgtType', 'category')->set('tgtId', $catalog['cats']['plumbing']->id)->call('addTarget')
            ->assertSee('Pick which choice');
        $component->set('tgtChoice', 'plumbing')->call('addTarget')->assertSee('Catalog target added');

        $target = PlanEntitlementTarget::where('plan_entitlement_id', $credit->id)->firstOrFail();
        $this->assertSame('category', $target->target_type);
        $this->assertSame($catalog['cats']['plumbing']->id, $target->target_id);
        $this->assertSame('plumbing', $target->choice_key);

        // Only real catalog rows can be mapped, and only valid choices.
        $component->set('tgtId', 999999)->set('tgtChoice', 'plumbing')->call('addTarget')->assertSee('does not exist');
        $component->set('tgtId', $catalog['cats']['ac']->id)->set('tgtChoice', 'appliance')->call('addTarget')->assertSee('Pick which choice');
        $this->assertSame(1, PlanEntitlementTarget::where('plan_entitlement_id', $credit->id)->count());

        $component->call('removeTarget', $target->id)->assertSee('removed');
        $this->assertSame(0, PlanEntitlementTarget::where('plan_entitlement_id', $credit->id)->count());
    }

    public function test_an_included_service_benefit_with_no_mapped_target_grants_nothing(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $catalog = $this->makePrimeCatalog();
        $plan = $this->seedPrimeSilver(); // NOT mapped
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $this->activatePrime($customer, $plan, $address);

        // Included-service benefits stay inert (never "every service is free"). The
        // Free Service Visit has no targets either, so it applies to any service that
        // carries a visiting charge — but only that charge.
        $booking = $this->bookService($customer, $address, $catalog['ac_jet']);
        $this->assertEquals(1800, $booking->price_quoted, 'An unmapped included-service benefit grants nothing, and a booking never spends a free cancellation.');
    }

    // ============================================== admin: authorization

    public function test_membership_knobs_are_admin_settings_not_hard_coded(): void
    {
        $this->assertSame(14, \App\Services\Plans\MembershipSettings::expiryReminderDays(), 'fallback until an admin saves a value');
        $this->assertSame(2, \App\Services\Plans\MembershipSettings::priorityBatchMultiplier());

        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(PlansManage::class)
            ->set('memberReminderDays', '30')->set('memberPriorityMultiplier', '3')
            ->call('saveMembershipSettings')->assertSee('Membership settings saved');

        $this->assertSame(30, \App\Services\Plans\MembershipSettings::expiryReminderDays());
        $this->assertSame(3, \App\Services\Plans\MembershipSettings::priorityBatchMultiplier());

        $viewer = $this->makeUserWithPermission('plans.view', 'global');
        Livewire::actingAs($viewer)->test(PlansManage::class)
            ->set('memberReminderDays', '1')->call('saveMembershipSettings')->assertSee('do not have permission');
        $this->assertSame(30, \App\Services\Plans\MembershipSettings::expiryReminderDays());
    }

    public function test_the_free_cancellation_amount_shown_is_the_live_visit_charge_setting(): void
    {
        $m = $this->primeMember();
        $visits = $m['plan']->entitlements->firstWhere('label', self::VISITS);
        $this->assertNull($visits->monetary_value, 'no stored copy of the visit charge');

        \App\Models\Setting::set('cancellation.visit_fee_type', 'flat');
        \App\Models\Setting::set('cancellation.visit_fee_value', '149');
        $this->assertEquals(149.0, app(\App\Services\Plans\MembershipPresenter::class)->entitlement($visits)['advertised_value']);

        \App\Models\Setting::set('cancellation.visit_fee_value', '199');
        $this->assertEquals(199.0, app(\App\Services\Plans\MembershipPresenter::class)->entitlement($visits)['advertised_value']);
    }

    public function test_a_viewer_without_manage_permission_cannot_change_plans_or_targets(): void
    {
        $m = $this->primeMember();
        $viewer = $this->makeUserWithPermission('plans.view', 'global');
        $ac = $m['plan']->entitlements->firstWhere('label', self::AC);
        $priceBefore = (string) $m['plan']->price;
        $targetsBefore = $ac->targets()->count();

        Livewire::actingAs($viewer)->test(PlansManage::class)
            ->call('startEditPlan', $m['plan']->id)->assertSee('do not have permission')
            ->call('toggleActive', $m['plan']->id)->assertSee('do not have permission')
            ->call('expand', $m['plan']->id)
            ->call('startEditEntitlement', $ac->id)->assertSee('do not have permission')
            ->call('toggleTargets', $ac->id)
            ->set('tgtType', 'category')->set('tgtId', $this->makePrimeCatalog()['cats']['ac']->id)->call('addTarget')
            ->assertSee('do not have permission')
            ->call('deleteEntitlement', $ac->id)->assertSee('do not have permission');

        $this->assertSame($priceBefore, (string) $m['plan']->fresh()->price);
        $this->assertTrue($m['plan']->fresh()->is_active);
        $this->assertSame($targetsBefore, $ac->targets()->count());
        $this->assertDatabaseHas('plan_entitlements', ['id' => $ac->id]);
    }

    public function test_a_user_with_no_plan_permission_cannot_open_either_admin_screen(): void
    {
        $nobody = $this->makeUserWithNoPermissions();

        Livewire::actingAs($nobody)->test(PlansManage::class)->assertForbidden();
        Livewire::actingAs($nobody)->test(SubscriptionsIndex::class)->assertForbidden();
    }

    public function test_admin_subscriptions_shows_dates_history_and_reverses_usage_through_the_ledger(): void
    {
        $m = $this->primeMember();
        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);
        $admin = $this->makeSuperAdmin();
        $consume = UsageLedger::where('booking_id', $booking->id)->where('event_type', 'consume')->firstOrFail();

        Livewire::actingAs($admin)->test(SubscriptionsIndex::class)
            ->assertSee('Registered: '.$m['address']->label)
            ->assertSee('1 / 2 left')
            ->call('toggleHistory', $m['subscription']->id)
            ->assertSee($booking->code)
            ->assertSee('service included')
            ->call('reverseUsage', $consume->id)
            ->assertSee('Usage reversed');

        $this->assertSame(2, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());
        $reversal = UsageLedger::where('related_usage_ledger_id', $consume->id)->where('event_type', 'reverse')->firstOrFail();
        $this->assertSame($admin->id, $reversal->created_by, 'The reversal is attributed to the admin who did it.');

        // Reversing again is a no-op, not a double restore.
        Livewire::actingAs($admin)->test(SubscriptionsIndex::class)->call('reverseUsage', $consume->id)->assertSee('already reversed');
        $this->assertSame(2, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());
    }

    public function test_only_subscriptions_manage_can_reverse_or_adjust_and_adjustments_stay_ledger_backed(): void
    {
        $m = $this->primeMember();
        $booking = $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);
        $consume = UsageLedger::where('booking_id', $booking->id)->where('event_type', 'consume')->firstOrFail();
        $viewer = $this->makeUserWithPermission('subscriptions.view', 'global');

        Livewire::actingAs($viewer)->test(SubscriptionsIndex::class)
            ->call('reverseUsage', $consume->id)->assertSee('do not have permission');
        $this->assertSame(1, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());

        // A manager's manual adjustment always leaves an attributed ledger row.
        $admin = $this->makeSuperAdmin();
        $balance = $this->balanceOf($m['subscription'], self::AC);
        Livewire::actingAs($admin)->test(SubscriptionsIndex::class)
            ->call('startAdjust', $balance->id)
            ->set('adjustQuantityDelta', '1')->set('adjustReason', 'Goodwill')
            ->call('confirmAdjust')->assertSee('recorded in the usage ledger');
        $adjust = UsageLedger::where('event_type', 'adjust')->firstOrFail();
        $this->assertSame($admin->id, $adjust->created_by);
        $this->assertSame('Goodwill', $adjust->reason);
        $this->assertSame(2, $this->balanceOf($m['subscription'], self::AC)->remainingQuantity());
    }

    public function test_admin_can_set_a_visiting_charge_on_a_service_but_never_above_its_price(): void
    {
        $catalog = $this->makePrimeCatalog();
        $service = $catalog['plumbing']; // ₹500
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(ServicesManage::class)
            ->call('edit', $service->id)
            ->assertSet('editVisitingCharge', '100.00')
            ->set('editVisitingCharge', '900')
            ->call('update')
            ->assertHasErrors(['editVisitingCharge']);
        $this->assertEquals(100, $service->fresh()->visiting_charge);

        Livewire::actingAs($admin)->test(ServicesManage::class)
            ->call('edit', $service->id)
            ->set('editVisitingCharge', '75')
            ->call('update')
            ->assertHasNoErrors();
        $this->assertEquals(75, $service->fresh()->visiting_charge);
    }

    // ================================================== ordering & money display

    public function test_benefits_are_always_listed_in_the_order_the_card_defines_them(): void
    {
        $m = $this->primeMember();
        $expected = [
            'Premium AC Jet Pump Service',
            'Appliance General Service',
            'Home Service Credit',
            '5 Free Cancellations (visit charge waived when no work is done)',
            'Priority-based service (allocation preference; no immediate-service guarantee)',
        ];

        $this->assertSame($expected, $m['plan']->fresh()->entitlements->pluck('label')->all(), 'Model relation.');

        $api = collect($this->actingAs($m['customer'], 'sanctum')->getJson('/api/plans?acting_as=customer')->json('plans'))
            ->firstWhere('slug', '1callfix-prime-silver');
        $this->assertSame($expected, collect($api['entitlements'])->pluck('label')->all(), 'Plans API.');

        $rows = $this->actingAs($m['customer'], 'sanctum')->getJson("/api/subscriptions/{$m['subscription']->id}/entitlements")->json('entitlements');
        $this->assertSame($expected, collect($rows)->pluck('name')->all(), 'Entitlements API.');

        $html = $this->get(route('customer.membership.show', $m['plan']))->getContent();
        $positions = array_map(fn ($label) => strpos($html, 'data-benefit="'.e($label).'"'), $expected);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Public membership page.');
    }

    public function test_a_benefit_with_no_money_value_never_reports_a_negative_money_balance(): void
    {
        $m = $this->primeMember();
        $this->bookService($m['customer'], $m['address'], $m['catalog']['plumbing']); // Home Service Credit, ₹500 waived
        $this->bookService($m['customer'], $m['address'], $m['catalog']['ac_jet']);   // AC, ₹1,500 waived

        $rows = collect($this->actingAs($m['customer'], 'sanctum')
            ->getJson("/api/subscriptions/{$m['subscription']->id}/entitlements")->json('entitlements'));

        $credit = $rows->firstWhere('name', self::CREDIT);
        $this->assertFalse($credit['monetary_tracked']);
        $this->assertEquals(0, $credit['remaining_monetary_value'], 'Not −500.');

        $ac = $rows->firstWhere('name', self::AC);
        $this->assertTrue($ac['monetary_tracked'], 'The AC benefit carries an advertised ₹3,000 value.');
        $this->assertEquals(1500, $ac['remaining_monetary_value'], '₹3,000 less the ₹1,500 used.');

        Livewire::actingAs($this->makeSuperAdmin())->test(SubscriptionsIndex::class)
            ->assertDontSee('₹-')
            ->assertSee('0 / 1 left')
            ->assertSee('included');
    }

    // ============================================================ priority

    public function test_a_members_booking_is_flagged_priority_and_a_non_members_is_not(): void
    {
        $m = $this->primeMember();
        $stranger = $this->makeCustomer();
        $strangerAddress = $this->makeAddress($stranger, $m['franchise'], $m['zone']);

        $this->assertTrue($this->bookService($m['customer'], $m['address'], $m['catalog']['painting'])->fresh()->is_priority, 'Priority is a membership benefit, independent of which service is booked.');
        $this->assertFalse($this->bookService($stranger, $strangerAddress, $m['catalog']['painting'])->fresh()->is_priority);

        $other = $this->makeAddress($m['customer'], $m['franchise'], $m['zone']);
        $this->assertFalse($this->bookService($m['customer'], $other, $m['catalog']['painting'])->fresh()->is_priority, 'Not at an unregistered address.');

        $m['subscription']->update(['status' => 'expired']);
        $this->assertFalse($this->bookService($m['customer'], $m['address'], $m['catalog']['painting'])->fresh()->is_priority, 'Not once the membership has lapsed.');
    }

    public function test_dispatch_asks_for_a_wider_offer_batch_for_a_priority_booking_and_nothing_else_changes(): void
    {
        Queue::fake();
        $m = $this->primeMember();
        $priority = $this->bookService($m['customer'], $m['address'], $m['catalog']['painting']);

        $stranger = $this->makeCustomer();
        $normal = $this->bookService($stranger, $this->makeAddress($stranger, $m['franchise'], $m['zone']), $m['catalog']['painting']);

        $asked = [];
        $dispatch = \Mockery::mock(DispatchService::class);
        $dispatch->shouldReceive('findCandidates')->andReturnUsing(function (Booking $b, int $limit) use (&$asked) {
            $asked[$b->id] = $limit;

            return collect();
        });

        (new ServiceMatchingJob($priority->id))->handle($dispatch);
        (new ServiceMatchingJob($normal->id))->handle($dispatch);

        $this->assertSame(5 * config('membership.priority_batch_multiplier'), $asked[$priority->id], 'Priority: a wider batch (default 5 × 2).');
        $this->assertSame(5, $asked[$normal->id], 'Everyone else: the normal batch.');
    }
}

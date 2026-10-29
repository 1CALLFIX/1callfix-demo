<?php

namespace Tests\Feature\Marketplace;

use App\Actions\CompleteMarketplaceOrderAction;
use App\Livewire\MarketplaceCommission\Manage;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceCommissionRule;
use App\Models\MarketplaceSettlement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Marketplace\MarketplaceCommissionResolver;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * E-commerce Phase 4 (shop commission, 2026-10-29). Owner decision: commission varies by category, vendor (shop),
 * franchise and city, set by Super Admin and franchise admins. Proves the priority order, the line-weighted fee on
 * a mixed order, the fallback, and that a franchise admin is held to their own franchise and the allowed range.
 */
class ShopCommissionTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function cat(string $name, ?int $parent = null): MarketplaceCategory
    {
        return MarketplaceCategory::create(['module' => Modules::COMMERCE, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::random(4), 'parent_id' => $parent, 'is_active' => true]);
    }

    private function rule(array $keys, float $pct): void
    {
        MarketplaceCommissionRule::create($keys + ['platform_fee_percent' => $pct]);
    }

    public function test_the_most_specific_rule_wins_and_no_rule_falls_back(): void
    {
        $s = $this->makeMarketplaceOrderScenario('ready');
        $store = $s['store'];
        $s['franchise']->update(['platform_fee_percent' => 7]);
        $electronics = $this->cat('Electronics');
        $phones = $this->cat('Phones', $electronics->id);
        $resolver = app(MarketplaceCommissionResolver::class);

        $this->assertEquals(7, $resolver->rateFor($store->fresh(), $phones->id), 'no rule: the franchise fee');

        $this->rule(['city_id' => $s['franchise']->city_id], 9);
        $this->assertEquals(9, $resolver->rateFor($store->fresh(), $phones->id));
        $this->rule(['franchise_id' => $s['franchise']->id], 10);
        $this->assertEquals(10, $resolver->rateFor($store->fresh(), $phones->id));
        $this->rule(['marketplace_category_id' => $electronics->id], 12);
        $this->assertEquals(12, $resolver->rateFor($store->fresh(), $phones->id), 'a sub-category inherits its parent');
        $this->rule(['marketplace_category_id' => $phones->id], 6);
        $this->assertEquals(6, $resolver->rateFor($store->fresh(), $phones->id), 'the exact category beats its parent');
        $this->rule(['marketplace_category_id' => $phones->id, 'franchise_id' => $s['franchise']->id], 8);
        $this->assertEquals(8, $resolver->rateFor($store->fresh(), $phones->id));
        $this->rule(['store_id' => $store->id], 15);
        $this->assertEquals(15, $resolver->rateFor($store->fresh(), $phones->id), 'a shop deal beats categories');
        $this->rule(['store_id' => $store->id, 'marketplace_category_id' => $phones->id], 4);
        $this->assertEquals(4, $resolver->rateFor($store->fresh(), $phones->id));
        $this->assertEquals(15, $resolver->rateFor($store->fresh(), null));
    }

    public function test_a_mixed_order_pays_the_line_weighted_fee_at_settlement(): void
    {
        $s = $this->makeMarketplaceOrderScenario('ready', ['order_type' => 'pickup', 'subtotal' => 400, 'total_amount' => 400, 'payment_method' => 'online', 'payment_status' => 'paid']);
        $s['franchise']->update(['commission_model' => 'none', 'commission_value' => 0]);
        $a = $this->cat('Books');
        $b = $this->cat('Toys');
        $pa = $this->makeProduct($s['store'], ['marketplace_category_id' => $a->id]);
        $pb = $this->makeProduct($s['store'], ['marketplace_category_id' => $b->id]);
        $s['order']->items()->create(['product_id' => $pa->id, 'product_name_snapshot' => 'Book', 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);
        $s['order']->items()->create(['product_id' => $pb->id, 'product_name_snapshot' => 'Toy', 'quantity' => 1, 'unit_price' => 300, 'line_total' => 300]);
        $this->rule(['marketplace_category_id' => $a->id], 10);
        $this->rule(['marketplace_category_id' => $b->id], 20);

        app(CompleteMarketplaceOrderAction::class)->execute($s['order']->id);

        $row = MarketplaceSettlement::where('marketplace_order_id', $s['order']->id)->sole();
        $this->assertEquals(70, $row->goods_platform, '100 x 10% + 300 x 20%');
        $this->assertEquals(330, $row->seller_share);
    }

    public function test_a_franchise_admin_sets_rules_only_for_their_franchise_and_within_the_range(): void
    {
        $s = $this->makeMarketplaceOrderScenario('ready');
        [, , $other] = $this->makeFranchiseTree();
        $user = $this->makeCustomer();
        $user->forceFill(['role' => 'franchise_owner'])->save();
        $role = Role::create(['name' => 'Commission', 'slug' => 'commission-'.Str::lower(Str::random(5))]);
        $role->permissions()->attach(Permission::where('slug', 'marketplace_commission.manage')->value('id'));
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => 'franchise', 'scope_id' => $s['franchise']->id]);

        $c = Livewire::actingAs($user->fresh())->test(Manage::class)->assertSet('franchiseId', $s['franchise']->id)
            ->set('percent', '12')->call('save')->assertHasErrors('percent'); // range not set by Super Admin yet

        Setting::set('marketplace.commission.franchise_min', '5');
        Setting::set('marketplace.commission.franchise_max', '15');
        $c->set('percent', '25')->call('save')->assertHasErrors('percent')
            ->set('franchiseId', $other->id)->set('percent', '10')->call('save')->assertHasErrors('franchiseId')
            ->set('franchiseId', $s['franchise']->id)->set('storeId', $s['store']->id)->set('percent', '10')->call('save')->assertHasNoErrors();

        $this->assertSame(1, MarketplaceCommissionRule::count());
        $this->assertEquals(10, app(MarketplaceCommissionResolver::class)->rateFor($s['store']->fresh(), null));
        $this->assertTrue(\App\Models\ActivityLog::where('description', 'Shop commission rule saved')->exists());

        $this->actingAs($this->makeCustomer())->get(route('admin.shop-commission.index'))->assertForbidden();
    }

    public function test_super_admin_sets_any_rule_and_sees_the_preview(): void
    {
        $s = $this->makeMarketplaceOrderScenario('ready');
        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)
            ->set('cityId', $s['franchise']->city_id)->set('percent', '42')->call('save')->assertHasNoErrors()
            ->set('previewStoreId', $s['store']->id)->assertViewHas('previewRate', 42.0);
    }
}

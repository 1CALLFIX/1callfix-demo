<?php

namespace Tests\Feature\Food;

use App\Livewire\FoodOrders\Settings as FoodSettingsPage;
use App\Livewire\Seller\AddOns;
use App\Livewire\Seller\Dashboard;
use App\Livewire\Seller\Products;
use App\Livewire\Seller\Shop;
use App\Livewire\Stores\Manage as StoresManage;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreHour;
use App\Models\User;
use App\Support\FoodSettings;
use App\Support\Modules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Food delivery F1 (2026-11-03): a restaurant is a seller whose store is module 'food'. Proven here: every restaurant
 * value comes from Admin → Food Settings (Super Admin only, audited); FSSAI is needed before approval and opening;
 * opening hours (incl. past midnight) decide when a restaurant takes orders; busy mode; veg/egg/non-veg dishes,
 * "sold out today" and add-on choice groups; a commerce shop sees none of it.
 */
class RestaurantSetupTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private Store $restaurant;
    private User $owner;
    private $franchise;
    private $zone;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $this->franchise, $this->zone] = $this->makeFranchiseTree();
        $provider = $this->makeStoreOwner($this->franchise, $this->zone);
        $this->owner = $provider->user;
        $this->restaurant = $this->makeStore($this->franchise, $this->zone, $provider, ['module' => Modules::FOOD, 'name' => 'Annapurna Mess']);
    }

    private function seller()
    {
        return Livewire::actingAs($this->owner);
    }

    // ── Super Admin controls ────────────────────────────────────────────

    public function test_food_settings_are_super_admin_only_validated_and_audit_logged(): void
    {
        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(FoodSettingsPage::class)
            ->set('inputs.food__accept_minutes', '99')->call('save')->assertHasErrors('inputs.food__accept_minutes')
            ->set('inputs.food__accept_minutes', '8')->set('inputs.food__packaging_charge_max', '25')->call('save')->assertHasNoErrors();

        $this->assertSame('8', FoodSettings::get('food.accept_minutes'));
        $this->assertSame(25.0, FoodSettings::packagingChargeMax());
        $this->assertSame('10', FoodSettings::REGISTRY['food.accept_minutes']['default'], 'owner decision: 10 minutes');
        $this->assertTrue(\App\Models\ActivityLog::where('description', 'like', '%food.accept_minutes%')->exists());

        $this->actingAs($this->owner)->get(route('admin.food.settings'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.food.settings'))->assertOk()->assertSee('Food Settings');
    }

    // ── restaurant details ──────────────────────────────────────────────

    public function test_restaurant_saves_fssai_cuisines_cost_and_cooking_time_from_the_admin_lists(): void
    {
        $this->seller()->test(Shop::class)
            ->set('fssai', '12345')->call('save')->assertHasErrors('fssai')
            ->set('fssai', '12345678901234')->set('cuisines', ['Biryani', 'Made up cuisine'])->call('save')->assertHasErrors('cuisines.1')
            ->set('cuisines', ['Biryani', 'Andhra', 'Chinese', 'South Indian'])->call('save')->assertHasErrors('cuisines')
            ->set('cuisines', ['Biryani', 'Andhra'])->set('costForTwo', '350')->set('prepMinutes', '25')->call('save')->assertHasNoErrors();

        $r = $this->restaurant->fresh();
        $this->assertSame('12345678901234', $r->fssai_number);
        $this->assertSame(['Biryani', 'Andhra'], $r->cuisineList());
        $this->assertEquals(350, $r->cost_for_two);
        $this->assertSame(25, $r->currentPrepMinutes());

        // The cuisine list is Super Admin's.
        Setting::set('food.cuisines', "Tiffins\nMeals");
        $this->assertSame(['Tiffins', 'Meals'], FoodSettings::cuisines());
    }

    public function test_packaging_charge_is_refused_until_super_admin_sets_a_highest_amount(): void
    {
        $this->seller()->test(Shop::class)->set('packagingCharge', '10')->call('save')->assertHasErrors('packagingCharge');
        $this->assertEquals(0, $this->restaurant->fresh()->packaging_charge);

        Setting::set('food.packaging_charge_max', '20');
        $this->seller()->test(Shop::class)->set('packagingCharge', '25')->call('save')->assertHasErrors('packagingCharge')
            ->set('packagingCharge', '15')->call('save')->assertHasNoErrors();
        $this->assertEquals(15, $this->restaurant->fresh()->packaging_charge);
    }

    public function test_a_commerce_shop_never_sees_restaurant_fields_or_pages(): void
    {
        $shop = $this->makeStore($this->franchise, $this->zone);
        $this->actingAs($shop->provider->user)->get(route('seller.shop'))->assertOk()->assertDontSee('FSSAI')->assertDontSee('Opening hours');
        $this->actingAs($shop->provider->user)->get(route('seller.add-ons'))->assertNotFound();
        $this->assertFalse($shop->missingFssai());
        $this->assertTrue($shop->isOpenNow(), 'hours never apply to a shop');
    }

    // ── opening hours ───────────────────────────────────────────────────

    public function test_opening_hours_decide_when_the_restaurant_takes_orders_including_past_midnight(): void
    {
        // Franchise timezone: the fixture's (IST in production). Monday = day 1.
        $this->seller()->test(Shop::class)
            ->set('hours.1', [['opens' => '11:00', 'closes' => '15:00'], ['opens' => '19:00', 'closes' => '01:00']])
            ->call('save')->assertHasNoErrors();
        $this->assertSame(2, StoreHour::where('store_id', $this->restaurant->id)->count());

        $r = $this->restaurant->fresh();
        $at = fn (string $local) => Carbon::parse($local, $r->localNow()->getTimezone())->utc();
        $this->assertFalse($r->isOpenNow($at('2026-11-02 10:59')), 'Monday before lunch');
        $this->assertTrue($r->isOpenNow($at('2026-11-02 12:00')), 'Monday lunch');
        $this->assertFalse($r->isOpenNow($at('2026-11-02 16:00')), 'Monday afternoon gap');
        $this->assertTrue($r->isOpenNow($at('2026-11-02 23:30')), 'Monday dinner');
        $this->assertTrue($r->isOpenNow($at('2026-11-03 00:30')), 'past midnight, still Monday dinner');
        $this->assertFalse($r->isOpenNow($at('2026-11-03 01:30')), 'after the late close');
        $this->assertFalse($r->isOpenNow($at('2026-11-03 12:00')), 'Tuesday has no hours = closed');

        $r->update(['is_open' => false]);
        $this->assertFalse($r->fresh()->isOpenNow($at('2026-11-02 12:00')), 'the closed switch always wins');

        $this->seller()->test(Shop::class)->set('hours.2', [['opens' => '10:00', 'closes' => '10:00']])->call('save')->assertHasErrors('hours.2.0.closes');
    }

    public function test_no_hours_at_all_means_open_whenever_switched_on(): void
    {
        $this->assertTrue($this->restaurant->isOpenNow());
        $this->seller()->test(Shop::class)->call('addSlot', 1)->call('copyMondayToAll')->assertSet('hours.5', [['opens' => '11:00', 'closes' => '22:00']]);
    }

    // ── FSSAI gate ──────────────────────────────────────────────────────

    public function test_no_restaurant_is_approved_or_opened_without_fssai_while_food_settings_needs_it(): void
    {
        $this->restaurant->update(['approval_status' => 'pending', 'is_active' => false, 'is_open' => false]);
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(StoresManage::class)->call('approveStore', $this->restaurant->id);
        $this->assertSame('pending', $this->restaurant->fresh()->approval_status);

        $this->restaurant->update(['fssai_number' => '12345678901234']);
        Livewire::actingAs($admin)->test(StoresManage::class)->call('approveStore', $this->restaurant->id);
        $this->assertTrue($this->restaurant->fresh()->isSellable());

        // Licence removed later: the restaurant cannot open.
        $this->restaurant->update(['fssai_number' => null, 'is_open' => false]);
        $this->seller()->test(Dashboard::class)->call('toggleOpen')->assertSee('FSSAI');
        $this->assertFalse($this->restaurant->fresh()->is_open);

        // Super Admin can switch the requirement off (the rule is a setting, like every food value).
        Setting::set('food.fssai_required', '0');
        $this->seller()->test(Dashboard::class)->call('toggleOpen');
        $this->assertTrue($this->restaurant->fresh()->is_open);
    }

    // ── busy mode ───────────────────────────────────────────────────────

    public function test_busy_adds_minutes_up_to_the_admin_limit_and_switches_itself_off(): void
    {
        $this->restaurant->update(['prep_minutes' => 20]);
        $this->seller()->test(Dashboard::class)->call('setBusy', 40)->assertSee('Choose one of the listed times');
        $this->seller()->test(Dashboard::class)->call('setBusy', 20);

        $r = $this->restaurant->fresh();
        $this->assertTrue($r->isBusy());
        $this->assertSame(40, $r->currentPrepMinutes());
        $this->assertSame(20, $r->currentPrepMinutes(now()->addHours(3)), 'busy ends after food.busy_max_hours (2)');

        $this->seller()->test(Dashboard::class)->call('clearBusy');
        $this->assertSame(20, $this->restaurant->fresh()->currentPrepMinutes());

        Setting::set('food.busy_max_extra_minutes', '0');
        $this->seller()->test(Dashboard::class)->call('setBusy', 10)->assertSee('Choose one of the listed times');
    }

    // ── menu ────────────────────────────────────────────────────────────

    public function test_a_dish_needs_veg_egg_or_non_veg_and_keeps_its_add_on_groups(): void
    {
        $group = AddOnGroup::create(['store_id' => $this->restaurant->id, 'name' => 'Choose chutney', 'min_select' => 1, 'max_select' => 1]);
        $otherShop = $this->makeStore($this->franchise, $this->zone, null, ['module' => Modules::FOOD]);
        $foreign = AddOnGroup::create(['store_id' => $otherShop->id, 'name' => 'Not mine']);

        $c = $this->seller()->test(Products::class)->call('startCreate')
            ->set('name', 'Masala Dosa')->set('price', '80')->call('save')->assertHasErrors('foodType')
            ->set('foodType', 'veg')->set('groupIds', [(string) $foreign->id])->call('save')->assertHasErrors('groupIds.0')
            ->set('groupIds', [(string) $group->id])->call('save')->assertHasNoErrors();

        $dish = Product::where('name', 'Masala Dosa')->sole();
        $this->assertSame('veg', $dish->food_type);
        $this->assertSame([$group->id], $dish->addOnGroups()->pluck('add_on_groups.id')->all());
        $this->assertSame(Products::RESTAURANT_STOCK, (int) $dish->stock, 'a dish is not counted stock');

        // "dosa" is not a blocked word anyway, but food words (blocked for the shop) never block a restaurant.
        Setting::set('marketplace.blocked_product_words', 'food');
        $this->seller()->test(Products::class)->call('startCreate')->set('name', 'Food platter')->set('price', '200')->set('foodType', 'non_veg')
            ->call('save')->assertHasNoErrors();
    }

    public function test_sold_out_today_hides_a_dish_until_the_restaurants_midnight(): void
    {
        $dish = $this->makeProduct($this->restaurant, ['food_type' => 'veg']);
        $this->seller()->test(Products::class)->call('toggleSoldOut', $dish->id);

        $dish->refresh();
        $this->assertTrue($dish->isSoldOutNow());
        $midnight = $this->restaurant->localNow()->endOfDay();
        $this->assertSame($midnight->utc()->format('Y-m-d H:i'), $dish->out_of_stock_until->utc()->format('Y-m-d H:i'));
        $this->assertFalse($dish->isSoldOutNow($midnight->copy()->addMinute()), 'back by itself tomorrow');

        $this->seller()->test(Products::class)->call('toggleSoldOut', $dish->id);
        $this->assertFalse($dish->fresh()->isSoldOutNow());
    }

    public function test_add_on_groups_have_a_choosing_rule_and_removed_options_are_kept_for_old_orders(): void
    {
        $this->seller()->test(AddOns::class)->call('startCreate')
            ->set('name', 'Extra toppings')->set('minSelect', '3')->set('maxSelect', '2')->call('save')->assertHasErrors('maxSelect')
            ->set('minSelect', '2')->set('maxSelect', '3')
            ->set('options', [['id' => null, 'name' => 'Cheese', 'price' => '30']])->call('save')->assertHasErrors('minSelect')
            ->set('minSelect', '0')->set('options', [['id' => null, 'name' => 'Cheese', 'price' => '30'], ['id' => null, 'name' => 'Onion', 'price' => '0']])
            ->call('save')->assertHasNoErrors();

        $group = AddOnGroup::where('name', 'Extra toppings')->sole();
        $this->assertSame('Optional, up to 3', $group->ruleText());
        $this->assertSame(2, $group->addOns()->count());

        $cheese = AddOn::where('name', 'Cheese')->sole();
        $this->assertNull($group->problemWith([$cheese->id]));
        $required = AddOnGroup::create(['store_id' => $this->restaurant->id, 'name' => 'Base', 'min_select' => 1, 'max_select' => 1]);
        $this->assertSame('Base: pick 1.', $required->problemWith([]));

        // Edit: Onion taken out → soft-deleted, not destroyed.
        $this->seller()->test(AddOns::class)->call('edit', $group->id)
            ->set('options', [['id' => $cheese->id, 'name' => 'Cheese', 'price' => '35']])->call('save')->assertHasNoErrors();
        $this->assertEquals(35, $cheese->fresh()->price);
        $this->assertSame(1, $group->addOns()->count());
        $this->assertSame(1, AddOn::onlyTrashed()->where('name', 'Onion')->count());

        // Another restaurant's group is not reachable.
        $other = AddOnGroup::create(['store_id' => $this->makeStore($this->franchise, $this->zone, null, ['module' => Modules::FOOD])->id, 'name' => 'X']);
        $this->seller()->test(AddOns::class)->call('edit', $other->id)->assertNotFound();
    }

    public function test_the_seller_menu_says_menu_add_ons_and_restaurant_for_a_restaurant(): void
    {
        $this->actingAs($this->owner)->get(route('seller.dashboard'))->assertOk()
            ->assertSee('Menu')->assertSee('Add-ons')->assertSee('Restaurant');
    }

    // ── test login (owner thumb rule) ───────────────────────────────────

    public function test_test_accounts_create_an_approved_test_restaurant_that_signs_in_and_disable_shuts_it(): void
    {
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $this->franchise->id])->assertSuccessful();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $this->franchise->id])->assertSuccessful();

        $user = User::where('email', 'test.restaurant@1callfix.test')->sole();
        $store = Store::whereHas('provider', fn ($q) => $q->where('user_id', $user->id))->sole();
        $this->assertTrue($store->isRestaurant());
        $this->assertTrue($store->isSellable() && $store->isOpenNow());
        $this->assertFalse($store->missingFssai());
        $this->assertSame(6, $store->products()->count(), 'rerun does not duplicate dishes');
        $this->assertSame(2, $store->addOnGroups()->count());
        $this->assertTrue($store->products()->where('name', 'Test Sold Out Dish')->sole()->isSoldOutNow());
        $this->assertSame('non_veg', $store->products()->where('name', 'Test Chicken Biryani')->value('food_type'));

        Livewire::test(\App\Livewire\Seller\Auth\Login::class)->set('identifier', 'test.restaurant@1callfix.test')->set('password', 'test-pass-123')
            ->call('login')->assertRedirect(route('seller.dashboard'));
        $this->actingAs($user)->get(route('seller.dashboard'))->assertOk()->assertSee('Test Restaurant');

        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();
        $this->assertFalse($store->fresh()->is_active);
        $this->assertSame('suspended', $user->fresh()->status);
    }
}

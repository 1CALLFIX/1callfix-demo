<?php

namespace Tests\Feature\Food;

use App\Livewire\Customer\Food\Home as FoodHome;
use App\Livewire\Customer\Food\RestaurantShow;
use App\Livewire\Customer\Shop\Bag;
use App\Livewire\Customer\Shop\Checkout;
use App\Livewire\Customer\Shop\OrderShow;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\CartItem;
use App\Models\City;
use App\Models\Franchise;
use App\Models\MarketplaceOrder;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreHour;
use App\Models\User;
use App\Models\Zone;
use App\Services\CartService;
use App\Services\GuestShopCart;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use App\Support\SiteText;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Food F3 (2026-11-03) — the customer food pages. Proven here: /{city}/food and each menu exist only while Food is on
 * for the city; restaurants without FSSAI and sold-out dishes never show; filters (pure veg, cuisine, a dish name);
 * a closed restaurant shows when it opens and takes nothing; plain dishes add straight in, a dish with choices opens
 * a sheet that enforces each group's rule; one restaurant per food cart (shop items untouched); the per-dish limit is
 * the Super Admin's; a visitor's dishes keep their add-ons when they sign in; checkout shows add-ons, packaging and
 * GST and its total is the order's; the order page says where the food is; the door, hub and sitemap list Food;
 * every food word has its Telugu.
 */
class FoodCustomerPagesTest extends TestCase
{
    use BookingFixtureHelpers;
    use LiveCity;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    private City $city;
    private Franchise $franchise;
    private Zone $zone;
    private Store $restaurant;
    private Product $meals;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $this->city->id)->firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        $this->activateMarketplaceModuleFor($this->franchise, Modules::FOOD);
        $this->restaurant = $this->restaurant('Annapurna Mess', ['cuisines' => 'Andhra, South Indian']);
        $this->meals = $this->dish($this->restaurant, 'Veg Meals', 120);
        $this->customer = $this->makeCustomer();
    }

    private function restaurant(string $name, array $overrides = []): Store
    {
        return $this->makeStore($this->franchise, $this->zone, null, array_merge([
            'module' => Modules::FOOD, 'name' => $name, 'slug' => Str::slug($name), 'fssai_number' => '12345678901234',
            'delivery_fee_flat' => 30, 'prep_minutes' => 20,
        ], $overrides));
    }

    private function dish(Store $store, string $name, float $price, string $type = 'veg', array $overrides = []): Product
    {
        return $this->makeProduct($store, array_merge(['name' => $name, 'slug' => Str::slug($name), 'price' => $price, 'stock' => 0, 'food_type' => $type], $overrides));
    }

    private function menu(?Store $store = null)
    {
        return Livewire::test(RestaurantShow::class, ['city' => 'nellore', 'restaurantSlug' => ($store ?? $this->restaurant)->slug]);
    }

    /** A dosa with a required "Chutney: pick 1" group and an optional "Extras (up to 2)". */
    private function dosa(): array
    {
        $dosa = $this->dish($this->restaurant, 'Masala Dosa', 80);
        $chutney = AddOnGroup::create(['store_id' => $this->restaurant->id, 'name' => 'Chutney', 'min_select' => 1, 'max_select' => 1, 'is_active' => true]);
        $extras = AddOnGroup::create(['store_id' => $this->restaurant->id, 'name' => 'Extras', 'min_select' => 0, 'max_select' => 2, 'is_active' => true]);
        $coconut = AddOn::create(['store_id' => $this->restaurant->id, 'add_on_group_id' => $chutney->id, 'name' => 'Coconut', 'price' => 0, 'is_active' => true]);
        $tomato = AddOn::create(['store_id' => $this->restaurant->id, 'add_on_group_id' => $chutney->id, 'name' => 'Tomato', 'price' => 5, 'is_active' => true]);
        $ghee = AddOn::create(['store_id' => $this->restaurant->id, 'add_on_group_id' => $extras->id, 'name' => 'Ghee', 'price' => 15, 'is_active' => true]);
        $dosa->addOnGroups()->sync([$chutney->id => ['position' => 0], $extras->id => ['position' => 1]]);

        return compact('dosa', 'chutney', 'extras', 'coconut', 'tomato', 'ghee');
    }

    // ── the city's food page ─────────────────────────────────────────────

    public function test_the_food_page_exists_only_while_food_is_on_and_lists_real_restaurants(): void
    {
        $this->restaurant('No Licence Kitchen', ['fssai_number' => null]);
        $chicken = $this->restaurant('Chicken Point', ['cuisines' => 'Biryani']);
        $this->dish($chicken, 'Chicken Biryani', 220, 'non_veg');
        $this->dish($this->restaurant('Empty Kitchen'), 'Draft dish', 50, 'veg', ['is_approved' => false]);

        $page = $this->get('/nellore/food')->assertOk();
        $page->assertSee('Annapurna Mess')->assertSee('Andhra · South Indian')->assertSee('Ready in about 20 min')
            ->assertSee('Chicken Point')->assertDontSee('No Licence Kitchen')->assertDontSee('Empty Kitchen');
        $this->get('/nellore/shop')->assertNotFound(); // a restaurant is never a shop (Commerce is off here)

        Livewire::test(FoodHome::class, ['city' => 'nellore'])->set('vegOnly', true)->assertSee('Annapurna Mess')->assertDontSee('Chicken Point')
            ->set('vegOnly', false)->set('search', 'biryani')->assertSee('Chicken Point')->assertDontSee('Annapurna Mess')
            ->set('search', '')->call('pickCuisine', 'Andhra')->assertSee('Annapurna Mess')->assertDontSee('Chicken Point');

        app(ModuleActivationService::class)->setActive(Modules::FOOD, 'franchise', $this->franchise->id, false);
        $this->get('/nellore/food')->assertNotFound();
        $this->get('/nellore/food/annapurna-mess')->assertNotFound();
    }

    public function test_a_closed_restaurant_says_when_it_opens_and_takes_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 15:00', 'Asia/Kolkata')->utc()); // Wednesday 3 PM
        StoreHour::create(['store_id' => $this->restaurant->id, 'day' => 3, 'opens_at' => '18:00', 'closes_at' => '23:00']);

        $this->get('/nellore/food')->assertOk()->assertSee('Opens at 6:00 PM');
        $this->menu()->assertSee('This restaurant is closed right now')->assertSee('Veg Meals');
        $this->assertSame(0, app(GuestShopCart::class)->count());

        $this->actingAs($this->customer);
        $this->menu()->call('add', $this->meals->id)->assertSee('Annapurna Mess is closed right now.');
        $this->assertSame(0, CartItem::count());
        Carbon::setTestNow();
    }

    // ── the menu ─────────────────────────────────────────────────────────

    public function test_the_menu_hides_sold_out_dishes_and_shows_the_marks_and_fssai(): void
    {
        $this->dish($this->restaurant, 'Egg Curry', 90, 'egg');
        $this->dish($this->restaurant, 'Pulihora', 60, 'veg', ['out_of_stock_until' => now()->addHours(4)]);

        $this->get('/nellore/food/annapurna-mess')->assertOk()->assertSee('Veg Meals')->assertSee('Egg Curry')->assertDontSee('Pulihora')
            ->assertSee('FSSAI licence no. 12345678901234')->assertSee('aria-label="Egg"', false);
        $this->menu()->set('vegOnly', true)->assertSee('Veg Meals')->assertDontSee('Egg Curry');
    }

    public function test_the_restaurant_and_every_dish_can_be_shared_and_a_shared_dish_link_jumps_to_that_dish(): void
    {
        $html = $this->get('/nellore/food/annapurna-mess')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-share-button'), 'one for the restaurant, one for its one dish');
        $this->assertSame(2, substr_count($html, 'data-save-button'), 'a ♡ beside each Share (2026-11-07)');
        $this->assertStringContainsString('id="dish-'.$this->meals->id.'"', $html);
        $this->assertStringContainsString('#dish-'.$this->meals->id, $html);
        $this->assertStringContainsString('aria-label="Share Veg Meals · ', $html);
    }

    public function test_plain_dishes_add_straight_in_for_a_visitor_and_a_customer(): void
    {
        $this->menu()->call('add', $this->meals->id)->call('add', $this->meals->id)->assertSet('customizing', null);
        $this->assertSame(2, app(GuestShopCart::class)->quantityFor($this->meals->id));

        Auth::login($this->customer); // signing in joins the visitor's dishes
        $this->assertSame(2, (int) CartItem::where('user_id', $this->customer->id)->value('quantity'));
        $this->menu()->call('add', $this->meals->id)->call('removeOne', $this->meals->id)->call('removeOne', $this->meals->id);
        $this->assertSame(1, (int) CartItem::where('user_id', $this->customer->id)->value('quantity'));
    }

    public function test_a_dish_with_choices_opens_a_sheet_that_keeps_each_groups_rule(): void
    {
        ['dosa' => $dosa, 'chutney' => $chutney, 'extras' => $extras, 'coconut' => $coconut, 'tomato' => $tomato, 'ghee' => $ghee] = $this->dosa();
        $this->actingAs($this->customer);

        $page = $this->menu()->call('add', $dosa->id)->assertSet('customizing', $dosa->id)
            ->assertSee('Chutney')->assertSee('Pick 1')->assertSee('Optional, up to 2');
        $page->call('addCustomized')->assertSet('sheetError', 'Chutney: Pick 1.');
        $this->assertSame(0, CartItem::count());

        $page->set("single.{$chutney->id}", (string) $tomato->id)->set("multi.{$extras->id}", [(string) $ghee->id])
            ->assertSee('₹100.00') // 80 + 5 + 15, shown as you choose
            ->call('addCustomized')->assertSet('customizing', null);
        $line = CartItem::where('user_id', $this->customer->id)->firstOrFail();
        $this->assertEqualsCanonicalizing([$tomato->id, $ghee->id], $line->add_on_ids);

        // A different choice is its own line; the menu counts both.
        $page->call('add', $dosa->id)->set("single.{$chutney->id}", (string) $coconut->id)->call('addCustomized');
        $this->assertSame(2, CartItem::where('product_id', $dosa->id)->count());
        $this->assertSame(2, $page->viewData('qty')[$dosa->id]);

        // The cart shows the add-ons and prices them in.
        Livewire::test(Bag::class)->assertSee('+ Tomato, Ghee')->assertSee('₹100.00')->assertSee('₹180.00');
    }

    /**
     * Live walk-through 2026-11-03: extras ticked in the browser were lost (the biryani went in plain, a third tick
     * said "not available"). A checkbox only builds a list when the value it is bound to is a list already, so the
     * sheet must open with an empty list for every "pick several" group; at the limit the other boxes are greyed out.
     */
    public function test_pick_several_groups_open_as_lists_and_grey_out_at_the_limit(): void
    {
        ['dosa' => $dosa, 'chutney' => $chutney, 'extras' => $extras, 'tomato' => $tomato, 'ghee' => $ghee] = $this->dosa();
        $this->actingAs($this->customer);

        $page = $this->menu()->call('add', $dosa->id)->assertSet("multi.{$extras->id}", [])->assertSet('single', []);
        $page->call('addCustomized')->assertSet('sheetError', 'Chutney: Pick 1.')
            ->set("single.{$chutney->id}", (string) $tomato->id)->assertSet('sheetError', ''); // a pick clears the message

        $other = \App\Models\AddOn::create(['store_id' => $this->restaurant->id, 'add_on_group_id' => $extras->id, 'name' => 'Podi', 'price' => 10, 'is_active' => true]);
        \App\Models\AddOn::create(['store_id' => $this->restaurant->id, 'add_on_group_id' => $extras->id, 'name' => 'Butter', 'price' => 20, 'is_active' => true]);
        $page->set("multi.{$extras->id}", [(string) $ghee->id, (string) $other->id])
            ->assertSeeHtml('disabled'); // the group is full: any other box is greyed out
        $page->call('addCustomized')->assertSet('customizing', null);
        $this->assertEqualsCanonicalizing([$tomato->id, $ghee->id, $other->id], CartItem::where('user_id', $this->customer->id)->sole()->add_on_ids);
    }

    public function test_one_restaurant_per_food_cart_and_shop_items_stay(): void
    {
        $this->actingAs($this->customer);
        $other = $this->restaurant('Chicken Point');
        $biryani = $this->dish($other, 'Chicken Biryani', 220, 'non_veg');
        $this->activateMarketplaceModuleFor($this->franchise);
        $shop = $this->makeStore($this->franchise, $this->zone, null, ['name' => 'Lakshmi Stores']);
        app(CartService::class)->add($this->customer, $this->makeProduct($shop, ['name' => 'Steel Bottle', 'stock' => 5]), 1);

        $this->menu()->call('add', $this->meals->id);
        $page = $this->menu($other)->call('add', $biryani->id)
            ->assertSet('conflict.inCart', 'Annapurna Mess')->assertSee('Start a new food cart?');
        $page->call('keepCart')->assertSet('conflict', null);
        $this->assertSame(0, CartItem::where('store_id', $other->id)->count());

        $page->call('add', $biryani->id)->call('replaceCart');
        $this->assertSame(0, CartItem::where('store_id', $this->restaurant->id)->count());
        $this->assertSame(1, CartItem::where('store_id', $other->id)->count());
        $this->assertSame(1, CartItem::where('store_id', $shop->id)->count(), 'the shop item is never touched');
    }

    public function test_the_most_of_one_dish_is_the_super_admins_setting_or_the_dishs_own_limit(): void
    {
        Setting::set('food.max_per_dish', '3');
        $this->actingAs($this->customer);
        $page = $this->menu();
        foreach (range(1, 4) as $i) {
            $page->call('add', $this->meals->id);
        }
        $page->assertSee('You can order up to 3 of Veg Meals.');
        $this->assertSame(3, (int) CartItem::where('product_id', $this->meals->id)->sum('quantity'));

        $this->meals->forceFill(['maximum_cart_quantity' => 1])->save();
        $this->assertSame(1, app(\App\Services\Food\FoodCart::class)->limitFor($this->meals->fresh()));
    }

    // ── visitor → sign in → checkout → order page ────────────────────────

    public function test_checkout_shows_add_ons_packaging_and_gst_and_charges_exactly_that(): void
    {
        Setting::set('food.packaging_charge_max', '20');
        Setting::set('food.gst_percent', '5');
        $this->restaurant->forceFill(['packaging_charge' => 10])->save();
        ['dosa' => $dosa, 'chutney' => $chutney, 'tomato' => $tomato] = $this->dosa();

        // A visitor picks it; the add-ons survive sign-in.
        $this->menu()->call('add', $dosa->id)->set("single.{$chutney->id}", (string) $tomato->id)->call('addCustomized');
        Auth::login($this->customer);
        $this->assertSame([$tomato->id], CartItem::where('user_id', $this->customer->id)->firstOrFail()->add_on_ids);

        $checkout = Livewire::test(Checkout::class, ['store' => $this->restaurant])->set('orderType', 'pickup')->set('paymentMethod', 'cash')
            ->assertSee('+ Tomato')->assertSee('Packaging')->assertSee('GST on food')
            ->assertSee('Note for the restaurant')->assertSee('Show your code at the restaurant')->assertDontSee('Note for the shop');
        // 85 food + 10 packaging + 5% GST on 95 (4.75) = 99.75
        $this->assertSame(99.75, $checkout->viewData('total'));
        $checkout->call('placeOrder');

        $order = MarketplaceOrder::firstOrFail();
        $this->assertSame('99.75', (string) $order->total_amount);

        Livewire::test(OrderShow::class, ['order' => $order])->assertSee('Waiting for the restaurant to accept')->assertSee('Sent to the restaurant')->assertDontSee('Sent to the shop')
            ->assertSee('+ Tomato')->assertSee('GST on food')->assertSee('Packaging');
        app(\App\Actions\AdvanceMarketplaceOrderAction::class)->execute($order->id, 'accepted', 25);
        Livewire::test(OrderShow::class, ['order' => $order->fresh()])->assertSee('The restaurant accepted your order. Ready about')->assertSee('Restaurant accepted')->assertDontSee('Shop accepted')
            ->assertViewHas('canCancel', false)->assertViewHas('canReturn', false);
    }

    public function test_checkout_says_when_the_address_is_too_far(): void
    {
        Setting::set('food.delivery_radius_km', '3');
        $this->restaurant->forceFill(['lat' => 1.0, 'lng' => 1.0])->save();
        $address = $this->makeAddress($this->customer, $this->franchise, $this->zone);
        $address->forceFill(['lat' => 1.0 + 5 / 111.19, 'lng' => 1.0])->save();
        app(CartService::class)->add($this->customer, $this->meals, 1);
        $this->actingAs($this->customer);

        Livewire::test(Checkout::class, ['store' => $this->restaurant])->set('orderType', 'delivery')->set('addressId', $address->id)
            ->assertSee('This restaurant delivers up to 3 km. Your address is 5 km away.');
    }

    // ── door, hub, sitemap, words ────────────────────────────────────────

    public function test_food_has_a_door_a_hub_entry_and_sitemap_entries(): void
    {
        $this->get('/nellore')->assertOk()->assertSee('data-door="food"', false)->assertSee('1 restaurant open now');
        $xml = $this->get('/sitemap-nellore.xml')->assertOk()->getContent();
        $this->assertStringContainsString('<loc>https://1callfix.com/nellore/food</loc>', $xml);
        $this->assertStringContainsString('<loc>https://1callfix.com/nellore/food/annapurna-mess</loc>', $xml);

        app(ModuleActivationService::class)->setActive(Modules::FOOD, 'franchise', $this->franchise->id, false);
        Cache::flush();
        $this->assertStringNotContainsString('data-door="food"', $this->get('/nellore')->getContent());
        $this->assertStringNotContainsString('/nellore/food', $this->get('/sitemap-nellore.xml')->getContent());
    }

    public function test_every_food_word_has_english_and_telugu(): void
    {
        $food = collect(SiteText::TEXTS)->filter(fn ($row, $key) => str_starts_with($key, 'food.') || str_starts_with($key, 'home.door.food.'));
        $this->assertGreaterThan(60, $food->count());
        foreach ($food as $key => [$group, $label, $en, $te]) {
            $this->assertNotSame('', trim($en), $key);
            $this->assertNotSame('', trim($te), $key);
        }
    }
}

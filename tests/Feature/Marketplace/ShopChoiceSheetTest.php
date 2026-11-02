<?php

namespace Tests\Feature\Marketplace;

use App\Livewire\Customer\Shop\Checkout;
use App\Livewire\Customer\Shop\ChoiceSheet;
use App\Models\CartItem;
use App\Services\CartService;
use App\Services\GuestShopCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-02, after comparing Amazon, Flipkart and Meesho checkouts:
 *  - the choice pop-up: "Choose" on a shop card opens sizes / colours on the same page (Swiggy / Zepto style);
 *  - checkout shows who receives it (name, address with pincode, mobile), lets the customer change a quantity or
 *    remove an item without going back, and says that ordering means agreeing to the Terms and Privacy Policy.
 */
class ShopChoiceSheetTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $city->update(['slug' => 'nellore']);
        $this->activateMarketplaceModuleFor($franchise);
        $store = $this->makeStore($franchise, $zone, null, ['name' => 'Lakshmi Stores', 'delivery_fee_flat' => 30]);
        $shirt = $this->makeProduct($store, ['name' => 'Cotton Shirt', 'price' => 300, 'stock' => 0, 'option_names' => ['Size']]);
        $m = $shirt->variants()->create(['name' => 'M', 'options' => ['Size' => 'M'], 'stock' => 2, 'is_active' => true]);
        $xl = $shirt->variants()->create(['name' => 'XL', 'options' => ['Size' => 'XL'], 'stock' => 9, 'price_override' => 350, 'is_active' => true]);
        $bottle = $this->makeProduct($store, ['name' => 'Steel Bottle', 'price' => 200, 'stock' => 3]);
        $customer = $this->makeCustomer();
        $this->w = compact('franchise', 'zone', 'store', 'shirt', 'm', 'xl', 'bottle', 'customer');
    }

    public function test_choose_opens_the_sizes_and_adds_the_picked_one_to_the_cart(): void
    {
        Livewire::actingAs($this->w['customer'])->test(ChoiceSheet::class)
            ->assertDontSee('Cotton Shirt')
            ->dispatch('open-choice-sheet', productId: $this->w['shirt']->id)
            ->assertSee('Cotton Shirt')->assertSee('2 left')
            ->call('addChoice')->assertSet('missingChoice', 'Size')->assertSee('Please select a size')
            ->call('pick', 'Size', 'XL')->assertSee('₹350.00')
            ->call('addChoice')->assertDispatched('shop-cart-changed')->assertSee('1 in cart')->assertSee('Done')
            ->call('addChoice')->assertSee('2 in cart')
            ->call('close')->assertDontSee('Cotton Shirt');

        $this->assertSame(2, (int) CartItem::where('user_id', $this->w['customer']->id)->where('product_variant_id', $this->w['xl']->id)->value('quantity'));
    }

    public function test_a_visitor_adds_from_the_pop_up_and_never_past_the_stock(): void
    {
        $sheet = Livewire::test(ChoiceSheet::class)->dispatch('open-choice-sheet', productId: $this->w['shirt']->id)
            ->call('pick', 'Size', 'M')->call('addChoice')->call('addChoice')->call('addChoice');

        $this->assertSame(2, app(GuestShopCart::class)->quantityFor($this->w['shirt']->id, $this->w['m']->id));
        $sheet->assertSee('Only 2 of Cotton Shirt (M) can be ordered.');
    }

    public function test_it_opens_only_for_a_product_on_sale_with_choices(): void
    {
        Livewire::test(ChoiceSheet::class)->dispatch('open-choice-sheet', productId: $this->w['bottle']->id)->assertSet('product', null);

        $this->w['shirt']->update(['is_active' => false]);
        Livewire::test(ChoiceSheet::class)->dispatch('open-choice-sheet', productId: $this->w['shirt']->id)->assertSet('product', null);
    }

    public function test_the_shop_page_has_the_pop_up_and_the_card_opens_it(): void
    {
        $this->get(route('customer.shop.store', ['nellore', $this->w['store']->slug]))->assertOk()
            ->assertSee('data-choice-sheet', false)
            ->assertSee('data-choose-product="'.$this->w['shirt']->id.'"', false)
            ->assertSee('open-choice-sheet', false)
            // wire:navigate on this link takes the click before the panel can open (found in the live check).
            ->assertDontSee('wire:navigate data-choose-product', false);
    }

    private function checkout()
    {
        return Livewire::actingAs($this->w['customer'])->test(Checkout::class, ['store' => $this->w['store']]);
    }

    public function test_checkout_shows_who_receives_it_and_the_terms(): void
    {
        $this->w['customer']->update(['name' => 'Ravi Kumar', 'phone' => '9876543210']);
        $address = $this->makeAddress($this->w['customer'], $this->w['franchise'], $this->w['zone']);
        $address->update(['pincode' => '524003']);
        app(CartService::class)->add($this->w['customer'], $this->w['bottle'], 1);

        $this->checkout()->assertSee('Ravi Kumar')->assertSee('9876543210')->assertSee('524003')
            ->assertSee('By placing your order, you agree to the')
            ->assertSeeHtml(route('customer.terms'))->assertSeeHtml(route('customer.privacy'));
    }

    public function test_checkout_changes_a_quantity_within_the_stock_and_removes_an_item(): void
    {
        $this->makeAddress($this->w['customer'], $this->w['franchise'], $this->w['zone']);
        $cart = app(CartService::class);
        $cart->add($this->w['customer'], $this->w['bottle'], 2);
        $cart->add($this->w['customer'], $this->w['shirt'], 1, $this->w['xl']);
        $bottleLine = CartItem::where('product_id', $this->w['bottle']->id)->first();
        $shirtLine = CartItem::where('product_variant_id', $this->w['xl']->id)->first();

        $page = $this->checkout()->assertViewHas('total', 780.0) // 400 + 350 + 30 delivery
            ->call('changeLine', $bottleLine->id, 1)->assertViewHas('total', 980.0)
            ->call('changeLine', $bottleLine->id, 1)->assertSee('Only 3 of Steel Bottle can be ordered.')
            ->call('changeLine', $bottleLine->id, -1)->assertViewHas('total', 780.0)
            ->call('removeLine', $shirtLine->id)->assertViewHas('total', 430.0)->assertNoRedirect();
        $this->assertNull(CartItem::find($shirtLine->id));

        // Someone else's cart line is never touched.
        $other = $this->makeCustomer();
        $cart->add($other, $this->w['bottle'], 1);
        $otherLine = CartItem::where('user_id', $other->id)->first();
        $page->call('removeLine', $otherLine->id);
        $this->assertNotNull(CartItem::find($otherLine->id));

        // The last item removed goes back to the cart.
        $page->call('removeLine', $bottleLine->id)->assertRedirect(route('customer.cart'));
    }
}

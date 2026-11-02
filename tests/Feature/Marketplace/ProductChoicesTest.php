<?php

namespace Tests\Feature\Marketplace;

use App\Actions\AdminCancelMarketplaceOrderAction;
use App\Actions\CreateMarketplaceOrderAction;
use App\Livewire\Customer\Shop\ProductShow;
use App\Livewire\Customer\Shop\StoreShow;
use App\Livewire\Seller\ProductChoices;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Product choices 2026-11-02 (owner: size, colour, units and the other choices a shop and food delivery need):
 *  - the seller sets the unit and up to two choice types; every combination gets its own price, stock and photo;
 *  - the customer picks each choice before adding; a sold-out combination cannot be added;
 *  - a product with choices shows "Choose" (and "From ₹…" when prices differ) on every card;
 *  - the product's discount applies to every choice, so cash stays off (online-only benefits);
 *  - stock is per choice, the product's stock is their total, and a cancelled order puts it back.
 */
class ProductChoicesTest extends TestCase
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
        $owner = $this->makeStoreOwner($franchise, $zone);
        $store = $this->makeStore($franchise, $zone, $owner, ['name' => 'Lakshmi Stores']);
        $shirt = $this->makeProduct($store, ['name' => 'Cotton Shirt', 'price' => 300, 'stock' => 0]);
        $customer = $this->makeCustomer();
        $this->w = compact('franchise', 'zone', 'owner', 'store', 'shirt', 'customer');
    }

    private function seller()
    {
        return Livewire::actingAs($this->w['owner']->user)->test(ProductChoices::class, ['product' => $this->w['shirt']->id]);
    }

    /** Size M/L x Colour Red/Blue; L / Blue costs ₹350; stocks 5, 0, 3, 2. */
    private function shirtWithChoices(): Product
    {
        $c = $this->seller()->set('unitValue', '1')->set('unit', 'piece')
            ->call('addType')->set('types.0.name', 'Size')->set('types.0.values', 'M, L')
            ->call('addType')->set('types.1.name', 'Colour')->set('types.1.values', 'Red,  Blue')
            ->call('buildRows')->assertCount('rows', 4);
        $stocks = ['M / Red' => '5', 'M / Blue' => '0', 'L / Red' => '3', 'L / Blue' => '2'];
        foreach ($c->get('rows') as $i => $row) {
            $c->set("rows.$i.stock", $stocks[$row['label']]);
            if ($row['label'] === 'L / Blue') {
                $c->set("rows.$i.price", '350');
            }
        }
        $c->call('save')->assertHasNoErrors();

        return $this->w['shirt']->fresh();
    }

    private function page()
    {
        return Livewire::actingAs($this->w['customer'])->test(ProductShow::class, ['city' => 'nellore', 'storeSlug' => $this->w['store']->slug, 'productSlug' => $this->w['shirt']->slug]);
    }

    private function variant(string $name): ProductVariant
    {
        return ProductVariant::where('product_id', $this->w['shirt']->id)->where('name', $name)->sole();
    }

    public function test_the_seller_sets_the_unit_and_every_size_colour_combination(): void
    {
        $shirt = $this->shirtWithChoices();

        $this->assertSame(['Size', 'Colour'], $shirt->option_names);
        $this->assertSame('1 piece', $shirt->unitLabel());
        $this->assertSame(10, $shirt->stock); // the total of the choices
        $this->assertSame(['Size' => 'L', 'Colour' => 'Blue'], $this->variant('L / Blue')->options);
        $this->assertSame(350.0, (float) $this->variant('L / Blue')->price_override);
        $this->assertNull($this->variant('M / Red')->price_override);

        // Reopening the page shows what was saved.
        $this->seller()->assertSet('types', [['name' => 'Size', 'values' => 'M, L'], ['name' => 'Colour', 'values' => 'Red, Blue']])
            ->assertCount('rows', 4)->assertSet('unit', 'piece');
    }

    public function test_a_dropped_value_switches_its_choices_off_and_keeps_them_for_old_orders(): void
    {
        $this->shirtWithChoices();

        $this->seller()->set('types.1.values', 'Red')->call('buildRows')->assertCount('rows', 2)->call('save')->assertHasNoErrors();

        $this->assertFalse($this->variant('L / Blue')->is_active);
        $this->assertTrue($this->variant('L / Red')->is_active);
        $this->assertSame(8, $this->w['shirt']->fresh()->stock);
        $this->assertSame(4, ProductVariant::where('product_id', $this->w['shirt']->id)->count());
    }

    public function test_changed_choices_must_be_laid_out_again_and_limits_hold(): void
    {
        $this->shirtWithChoices();
        $this->seller()->set('types.0.values', 'M, L, XL')->call('save')->assertHasErrors('types');

        $this->seller()->set('types.0.name', 'Colour')->call('buildRows')->assertHasErrors('types.1.name');
        $this->seller()->set('unit', 'barrel')->call('save')->assertHasErrors('unit');
        $this->seller()->set('types.0.values', implode(',', range(1, 11)))->set('types.1.values', implode(',', range(1, 10)))
            ->call('buildRows')->assertHasErrors('types');
    }

    public function test_removing_all_choices_sells_it_as_one_item_again(): void
    {
        $this->shirtWithChoices();
        $this->seller()->call('removeChoices');

        $shirt = $this->w['shirt']->fresh();
        $this->assertNull($shirt->option_names);
        $this->assertSame(10, $shirt->stock);
        $this->assertFalse($shirt->hasChoices());
        $this->assertSame(0, $shirt->variants()->where('is_active', true)->count());
    }

    public function test_a_seller_cannot_open_another_shops_product(): void
    {
        $other = $this->makeStore($this->w['franchise'], $this->w['zone'], $this->makeStoreOwner($this->w['franchise'], $this->w['zone']));
        $theirs = $this->makeProduct($other);

        Livewire::actingAs($this->w['owner']->user)->test(ProductChoices::class, ['product' => $theirs->id])->assertStatus(404);
    }

    public function test_the_customer_picks_size_and_colour_before_adding(): void
    {
        $this->shirtWithChoices();

        $page = $this->page()->assertSee('Size')->assertSee('Colour')->assertSee('Price for 1 piece')
            ->assertSee('From')->assertSee('₹300.00')
            ->call('addChoice')->assertSee('Choose a size first.');
        $this->assertSame(0, CartItem::count());

        $page->call('pick', 'Size', 'L')->call('pick', 'Colour', 'Blue')->assertSee('₹350.00')->assertSee('Only 2 left')
            ->call('addChoice')->call('addChoice')->assertSee('2 in bag')
            ->call('addChoice')->assertSee('Only 2 of Cotton Shirt (L / Blue) can be ordered.');

        $line = CartItem::sole();
        $this->assertSame($this->variant('L / Blue')->id, $line->product_variant_id);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(350.0, $line->unitPrice());

        $page->call('pick', 'Size', 'M')->call('pick', 'Colour', 'Blue')->assertSee('M / Blue is sold out. Try another.')
            ->call('addChoice');
        $this->assertSame(1, CartItem::count());
    }

    public function test_cards_say_choose_and_a_plain_add_is_refused(): void
    {
        $this->shirtWithChoices();

        Livewire::actingAs($this->w['customer'])->test(StoreShow::class, ['city' => 'nellore', 'storeSlug' => $this->w['store']->slug])
            ->assertSee('Choose')->assertSee('From')->assertSee('1 piece')
            ->call('addToCart', $this->w['shirt']->id)->assertSee('Choose a size first.');
        $this->assertSame(0, CartItem::count());
    }

    public function test_the_discount_applies_to_every_choice_so_cash_stays_off(): void
    {
        $this->shirtWithChoices();
        $this->w['shirt']->update(['discount_percent' => 10]);
        $this->makeAddress($this->w['customer'], $this->w['franchise'], $this->w['zone']);
        $variant = $this->variant('L / Blue');
        app(CartService::class)->add($this->w['customer'], $this->w['shirt']->fresh(), 1, $variant->fresh());

        $line = CartItem::sole();
        $this->assertTrue($line->isDiscounted());
        $this->assertSame(315.0, $line->unitPrice());
        $this->assertSame(350.0, $line->listUnitPrice());

        $this->expectException(\RuntimeException::class);
        app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $this->w['store']->id, 'customer_id' => $this->w['customer']->id, 'order_type' => 'pickup', 'payment_method' => 'cash',
        ]);
    }

    public function test_an_order_takes_stock_from_the_choice_and_a_cancel_puts_it_back(): void
    {
        $this->shirtWithChoices();
        app(CartService::class)->add($this->w['customer'], $this->w['shirt']->fresh(), 2, $this->variant('M / Red'));

        $order = app(CreateMarketplaceOrderAction::class)->execute([
            'store_id' => $this->w['store']->id, 'customer_id' => $this->w['customer']->id, 'order_type' => 'pickup', 'payment_method' => 'cash',
        ]);

        $this->assertSame('M / Red', $order->items->first()->variant_name_snapshot);
        $this->assertSame(3, $this->variant('M / Red')->stock);
        $this->assertSame(8, $this->w['shirt']->fresh()->stock);

        app(AdminCancelMarketplaceOrderAction::class)->execute($order->id, 'Test');
        $this->assertSame(5, $this->variant('M / Red')->stock);
        $this->assertSame(10, $this->w['shirt']->fresh()->stock);
    }

    public function test_units_and_choice_names_are_super_admin_lists(): void
    {
        Setting::updateOrCreate(['key' => 'marketplace.product_units', 'scope_type' => 'global', 'scope_id' => null], ['value' => "g\nkg"]);

        $this->seller()->assertSee('kg')->set('unitValue', '500')->set('unit', 'piece')->call('save')->assertHasErrors('unit')
            ->set('unit', 'g')->call('save')->assertHasNoErrors();
        $this->assertSame('500 g', $this->w['shirt']->fresh()->unitLabel());
        $this->assertContains('Portion', \App\Support\MarketplaceSettings::lines('marketplace.choice_names'));
    }

    public function test_a_food_portion_works_the_same_way(): void
    {
        $this->seller()->call('addType')->set('types.0.name', 'Portion')->set('types.0.values', 'Half, Full')->call('buildRows')
            ->set('rows.0.stock', '20')->set('rows.1.stock', '20')->set('rows.1.price', '450')->call('save')->assertHasNoErrors();

        $page = $this->page()->assertSee('Portion')->call('pick', 'Portion', 'Full')->assertSee('₹450.00')->call('addChoice');
        $this->assertSame('Full', CartItem::sole()->variant->name);
        $page->assertHasNoErrors();
    }
}

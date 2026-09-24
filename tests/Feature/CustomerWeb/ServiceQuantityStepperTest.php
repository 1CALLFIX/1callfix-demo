<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Cart\Index as CartIndex;
use App\Livewire\Customer\CartCount;
use App\Livewire\Customer\Catalog\ServiceShow;
use App\Livewire\Customer\Checkout;
use App\Models\BookingBundle;
use App\Models\ServiceCartItem;
use App\Models\Wallet;
use App\Services\Customer\ServiceCartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * 1CF-LAUNCH-20260924-QUANTITY-STEPPER: the −/n/+ stepper on the service
 * detail page (ServiceShow) and the per-line maximum in ServiceCartService.
 */
class ServiceQuantityStepperTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function cart(): ServiceCartService
    {
        return app(ServiceCartService::class);
    }

    /** Not try/fail/catch(\RuntimeException): PHPUnit's own fail() is a RuntimeException and would be swallowed. */
    private function assertRejectedAsOverMax(\Closure $call): void
    {
        $thrown = null;
        try {
            $call();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'expected a rejection above the maximum');
        $this->assertSame(ServiceCartService::maxQuantityMessage(), $thrown->getMessage());
    }

    // ---- A: quantity 2 -> one line of 2 -> two bookings at checkout ----

    public function test_stepping_to_two_adds_one_line_of_two_and_stays_on_the_page(): void
    {
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory(), ['base_price' => 500]);

        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service])
            ->assertSet('quantity', 1)
            ->call('incrementQuantity')
            ->assertSet('quantity', 2)
            ->assertSee('1,000.00')          // subtotal = 2 x 500
            ->call('addToCart')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSet('cartNotice', 'Added to your cart.')
            ->assertDispatched('cart-updated');

        $line = ServiceCartItem::where('user_id', $customer->id)->sole();
        $this->assertSame(2, $line->quantity);
    }

    public function test_a_line_added_with_the_stepper_checks_out_as_two_bookings(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $this->makeProviderIn($franchise, $zone);
        $service = $this->makeService($this->makeCategory(['module' => 'service']), ['base_price' => 500]);
        Wallet::updateOrCreate(['user_id' => $customer->id], ['balance' => 100000]);

        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service])
            ->call('incrementQuantity')
            ->call('addToCart')
            ->assertHasNoErrors();

        Livewire::actingAs($customer)->test(Checkout::class)
            ->set('addressId', $address->id)
            ->call('next')
            ->call('next')
            ->call('next')
            ->set('paymentMethod', 'wallet')
            ->call('place')
            ->assertHasNoErrors();

        $bundle = BookingBundle::where('customer_id', $customer->id)->sole();
        $this->assertSame(2, $bundle->children()->count());
    }

    // ---- B: 0 removes the line ----

    public function test_stepping_an_existing_line_to_zero_removes_it(): void
    {
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());
        $this->cart()->add($customer, $service, quantity: 2);

        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service])
            ->assertSet('quantity', 2)
            ->call('decrementQuantity')
            ->call('decrementQuantity')
            ->assertSet('quantity', 0)
            ->assertSee('Remove from cart')
            ->call('addToCart')
            ->assertHasNoErrors()
            ->assertSet('cartNotice', 'Removed from your cart.')
            ->assertSet('cartItemId', null)
            ->assertSet('quantity', 1)
            ->assertDispatched('cart-updated');

        $this->assertSame(0, ServiceCartItem::where('user_id', $customer->id)->count());
    }

    public function test_a_new_line_cannot_be_stepped_below_one(): void
    {
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());

        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service])
            ->call('decrementQuantity')
            ->assertSet('quantity', 1);
    }

    // ---- C: the maximum is a rejection, not a silent cap ----

    public function test_stepping_past_the_maximum_is_refused_with_a_message(): void
    {
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());
        $component = Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service]);

        for ($i = 1; $i < ServiceCartService::MAX_QUANTITY; $i++) {
            $component->call('incrementQuantity');
        }

        $component->assertSet('quantity', ServiceCartService::MAX_QUANTITY)
            ->assertHasNoErrors()
            ->call('incrementQuantity')
            ->assertSet('quantity', ServiceCartService::MAX_QUANTITY)
            ->assertHasErrors('cart')
            ->assertSee(ServiceCartService::maxQuantityMessage());
    }

    public function test_a_tampered_quantity_over_the_maximum_is_rejected_server_side(): void
    {
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());

        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service])
            ->set('quantity', 50)
            ->call('addToCart')
            ->assertHasErrors('cart');

        $this->assertSame(0, ServiceCartItem::where('user_id', $customer->id)->count());
    }

    public function test_the_service_rejects_over_the_maximum_on_add_merge_and_update(): void
    {
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());
        $max = ServiceCartService::MAX_QUANTITY;

        $this->assertRejectedAsOverMax(fn () => $this->cart()->add($customer, $service, quantity: $max + 1));

        $line = $this->cart()->add($customer, $service, quantity: $max);

        $this->assertRejectedAsOverMax(fn () => $this->cart()->add($customer, $service, quantity: 1)); // merges into the same line
        $this->assertRejectedAsOverMax(fn () => $this->cart()->updateQuantity($line->fresh(), $max + 1));

        $this->assertSame($max, $line->fresh()->quantity);
    }

    public function test_the_cart_page_stepper_shows_the_maximum_instead_of_crashing(): void
    {
        $customer = $this->makeCustomer();
        $line = $this->cart()->add($customer, $this->makeService($this->makeCategory()), quantity: ServiceCartService::MAX_QUANTITY);

        Livewire::actingAs($customer)->test(CartIndex::class)
            ->call('changeQty', $line->id, 1)
            ->assertSet("qtyErrors.{$line->id}", ServiceCartService::maxQuantityMessage())
            ->assertSee(ServiceCartService::maxQuantityMessage())
            ->assertNotDispatched('cart-updated');

        $this->assertSame(ServiceCartService::MAX_QUANTITY, $line->fresh()->quantity);
    }

    // ---- D: opens on the existing cart line ----

    public function test_the_stepper_opens_on_the_existing_line_and_updates_it_in_place(): void
    {
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());
        $when = now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i');

        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service])
            ->set('preferredAt', $when)
            ->set('customerNote', 'Gate code 42')
            ->call('incrementQuantity')
            ->call('incrementQuantity')
            ->call('addToCart');

        $line = ServiceCartItem::where('user_id', $customer->id)->sole();

        // A fresh visit to the page, as after navigating away and back.
        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service])
            ->assertSet('cartItemId', $line->id)
            ->assertSet('quantity', 3)
            ->assertSet('preferredAt', $when)
            ->assertSet('customerNote', 'Gate code 42')
            ->assertSee('Update cart')
            ->call('incrementQuantity')
            ->call('addToCart')
            ->assertHasNoErrors()
            ->assertSet('cartNotice', 'Cart updated.');

        $this->assertSame(1, ServiceCartItem::where('user_id', $customer->id)->count());
        $this->assertSame(4, $line->fresh()->quantity);
    }

    public function test_another_customers_line_is_never_loaded(): void
    {
        $service = $this->makeService($this->makeCategory());
        $this->cart()->add($this->makeCustomer(), $service, quantity: 5);

        Livewire::actingAs($this->makeCustomer())->test(ServiceShow::class, ['service' => $service])
            ->assertSet('cartItemId', null)
            ->assertSet('quantity', 1)
            ->assertSee('Add to cart');
    }

    // ---- E: the badge follows without a reload ----

    public function test_the_topbar_badge_follows_the_stepper_through_cart_updated(): void
    {
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());
        $badge = Livewire::actingAs($customer)->test(CartCount::class)->assertSet('count', 0);

        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service])
            ->call('incrementQuantity')
            ->call('incrementQuantity')
            ->call('addToCart')
            ->assertDispatched('cart-updated');

        $badge->dispatch('cart-updated')->assertSet('count', 3);
    }

    public function test_the_cart_page_stepper_also_refreshes_the_badge(): void
    {
        $customer = $this->makeCustomer();
        $line = $this->cart()->add($customer, $this->makeService($this->makeCategory()));

        Livewire::actingAs($customer)->test(CartIndex::class)
            ->call('changeQty', $line->id, 1)
            ->assertDispatched('cart-updated');
    }

    /**
     * The PR #5 failure class: a handler bound in a one-shot
     * 'livewire:init' listener is dead after wire:navigate. The stepper uses
     * only wire:click round-trips and the badge listens via #[On], both
     * re-bound on every component init — keep it that way.
     */
    public function test_the_service_page_binds_no_one_shot_javascript_listeners(): void
    {
        $source = file_get_contents(resource_path('views/livewire/customer/catalog/service-show.blade.php'));

        $this->assertStringNotContainsString("addEventListener('livewire:init'", $source);
        $this->assertStringNotContainsString('Livewire.on(', $source);
        $this->assertStringNotContainsString('<script', $source);
    }
}

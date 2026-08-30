<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Bundles\Show as BundleShow;
use App\Livewire\Customer\Checkout;
use App\Models\Booking;
use App\Models\BookingBundle;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Cart -> checkout -> ONE BookingBundle via the existing
 * CreateBookingBundleAction. Quantity fan-out, wallet capture + cart clear,
 * double-submit idempotency, N=1, and the bundle page's IDOR guard.
 */
class CheckoutBundleTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    private function world(): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $customer = $this->makeCustomer();
        $address = $this->makeAddress($customer, $franchise, $zone);
        $this->makeProviderIn($franchise, $zone);
        $cat = $this->makeCategory(['module' => 'service']);

        return compact('customer', 'address', 'franchise', 'zone', 'cat');
    }

    private function fund($user, float $amount): void
    {
        Wallet::updateOrCreate(['user_id' => $user->id], ['balance' => $amount]);
    }

    private function cart()
    {
        return app(\App\Services\Customer\ServiceCartService::class);
    }

    public function test_wallet_checkout_creates_one_bundle_with_a_child_per_quantity_and_clears_the_cart(): void
    {
        ['customer' => $customer, 'address' => $address, 'cat' => $cat] = $this->world();
        $this->fund($customer, 100000);

        $svcA = $this->makeService($cat, ['name' => 'AC Service', 'base_price' => 500]);
        $svcB = $this->makeService($cat, ['name' => 'Fridge Repair', 'base_price' => 700]);
        $this->cart()->add($customer, $svcA, quantity: 2);
        $this->cart()->add($customer, $svcB, quantity: 1);

        Livewire::actingAs($customer)->test(Checkout::class)
            ->set('addressId', $address->id)
            ->call('next')   // address -> schedule
            ->call('next')   // schedule (ASAP) -> review
            ->call('next')   // review -> pay
            ->set('paymentMethod', 'wallet')
            ->call('place')
            ->assertHasNoErrors();

        $bundle = BookingBundle::where('customer_id', $customer->id)->sole();
        $this->assertSame(3, $bundle->children()->count());          // 2 + 1
        $this->assertSame(1700.0, (float) $bundle->total_price_quoted); // 500*2 + 700
        $this->assertSame('paid', $bundle->payment_status);
        $this->assertSame(0, \App\Models\ServiceCartItem::where('user_id', $customer->id)->count());
    }

    public function test_a_single_line_cart_still_checks_out_as_a_bundle(): void
    {
        ['customer' => $customer, 'address' => $address, 'cat' => $cat] = $this->world();
        $this->fund($customer, 100000);
        $this->cart()->add($customer, $this->makeService($cat, ['base_price' => 400]));

        Livewire::actingAs($customer)->test(Checkout::class)
            ->set('addressId', $address->id)
            ->call('next')->call('next')->call('next')
            ->set('paymentMethod', 'wallet')
            ->call('place')
            ->assertHasNoErrors();

        $this->assertSame(1, BookingBundle::where('customer_id', $customer->id)->count());
        $this->assertSame(1, Booking::where('customer_id', $customer->id)->count());
    }

    public function test_a_double_submit_with_the_same_key_does_not_book_twice(): void
    {
        ['customer' => $customer, 'address' => $address, 'cat' => $cat] = $this->world();
        $this->fund($customer, 100000);
        $this->cart()->add($customer, $this->makeService($cat, ['base_price' => 400]));

        $component = Livewire::actingAs($customer)->test(Checkout::class)
            ->set('addressId', $address->id)
            ->call('next')->call('next')->call('next')
            ->set('paymentMethod', 'wallet');

        $component->call('place');
        // Re-add something and submit again on the same component (same idempotencyKey).
        $this->cart()->add($customer, $this->makeService($cat, ['base_price' => 999]));
        $component->call('place');

        $this->assertSame(1, BookingBundle::where('customer_id', $customer->id)->count());
    }

    public function test_checkout_needs_a_valid_address(): void
    {
        ['customer' => $customer, 'cat' => $cat] = $this->world();
        $this->cart()->add($customer, $this->makeService($cat));

        Livewire::actingAs($customer)->test(Checkout::class)
            ->set('addressId', null)
            ->call('next')
            ->assertSet('step', 'address');
    }

    public function test_the_bundle_page_404s_for_a_stranger(): void
    {
        ['customer' => $customer, 'address' => $address, 'cat' => $cat] = $this->world();
        $this->fund($customer, 100000);
        $this->cart()->add($customer, $this->makeService($cat, ['base_price' => 400]));

        Livewire::actingAs($customer)->test(Checkout::class)
            ->set('addressId', $address->id)
            ->call('next')->call('next')->call('next')
            ->set('paymentMethod', 'wallet')
            ->call('place');

        $bundle = BookingBundle::where('customer_id', $customer->id)->sole();
        $stranger = $this->makeCustomer();

        Livewire::actingAs($stranger)->test(BundleShow::class, ['bundle' => $bundle])->assertStatus(404);
        Livewire::actingAs($customer)->test(BundleShow::class, ['bundle' => $bundle])->assertOk();
    }
}

<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Catalog\ServiceShow;
use App\Models\ServiceCartItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * "Add to cart" on the service detail page (ServiceShow::addToCart).
 */
class AddToCartTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $service = $this->makeService($this->makeCategory());

        Livewire::test(ServiceShow::class, ['service' => $service])
            ->call('addToCart')
            ->assertRedirect(route('customer.login', ['intended' => route('customer.services.show', $service->id)]));

        $this->assertSame(0, ServiceCartItem::count());
    }

    public function test_an_authed_customer_adds_a_line_with_a_preferred_time(): void
    {
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory(), ['name' => 'AC Service']);
        $when = now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i');

        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service])
            ->set('preferredAt', $when)
            ->set('customerNote', 'Second floor')
            ->call('addToCart')
            ->assertHasNoErrors()
            ->assertSet('cartNotice', 'Added to your cart.');

        $item = ServiceCartItem::where('user_id', $customer->id)->sole();
        $this->assertSame($service->id, $item->service_id);
        $this->assertSame('Second floor', $item->customer_note);
        $this->assertNotNull($item->scheduled_at);
    }

    public function test_a_required_option_must_be_answered_first(): void
    {
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());
        // A required multi-choice group is left unanswered by preselect.
        $this->makeOptionGroup($service, ['Extra A' => 100, 'Extra B' => 150], required: true, multiple: true, name: 'Extras');

        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service])
            ->call('addToCart')
            ->assertHasErrors('cart');

        $this->assertSame(0, ServiceCartItem::where('user_id', $customer->id)->count());
    }

    public function test_a_past_preferred_time_is_rejected(): void
    {
        $customer = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory());

        Livewire::actingAs($customer)->test(ServiceShow::class, ['service' => $service])
            ->set('preferredAt', now()->subDay()->format('Y-m-d\TH:i'))
            ->call('addToCart')
            ->assertHasErrors('cart');

        $this->assertSame(0, ServiceCartItem::where('user_id', $customer->id)->count());
    }
}

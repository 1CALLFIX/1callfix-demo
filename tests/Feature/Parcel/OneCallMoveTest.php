<?php

namespace Tests\Feature\Parcel;

use App\Livewire\Customer\Parcel\Create;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Services\Parcel\ParcelSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * 1CallMove (owner 2026-11-27): once a 3-wheeler or truck is priced, the booking page is the transport brand and opens
 * with big buttons (Send a parcel / Book a 3-wheeler / Book a truck / Shift house), only for what can be booked here.
 * While only parcels are live the page is exactly as before.
 */
class OneCallMoveTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    private function world(): array
    {
        Queue::fake();
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->activateParcelFor($franchise);
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff');
    }

    private function price(string $vehicle, bool $helpers = true): void
    {
        Setting::set(ParcelSettings::vehicleKey($vehicle, 'base_fare'), '300');
        Setting::set(ParcelSettings::vehicleKey($vehicle, 'base_km'), '1');
        Setting::set(ParcelSettings::vehicleKey($vehicle, 'per_km_rate'), '25');
        if ($helpers) {
            Setting::set(ParcelSettings::vehicleKey($vehicle, 'helper_fee'), '150');
        }
    }

    private function page(array $w, array $query = [])
    {
        return Livewire::withQueryParams($query)->actingAs($w['customer'])->test(Create::class)
            ->set('dropMode', 'saved')->set('dropAddressId', $w['dropoff']->id);
    }

    private function buttons(string $html): array
    {
        preg_match_all('/data-move="([a-z_]+)"/', $html, $m);

        return $m[1];
    }

    public function test_only_parcels_live_keeps_the_page_as_before(): void
    {
        $w = $this->world();
        $this->assertSame([], ParcelSettings::moveChoices());

        $this->page($w)
            ->assertDontSee('data-testid="move-choices"', false)
            ->assertDontSee('data-testid="move-header"', false)
            ->assertDontSee('1CallMove')
            ->assertSee('Send a parcel');
    }

    public function test_the_buttons_show_only_what_can_be_booked_here(): void
    {
        $w = $this->world();
        $this->price('mini_truck');

        $html = $this->page($w)
            ->assertSee('data-testid="move-header"', false)
            ->assertSee('by 1CallFix')
            ->assertDontSee('1CallMove', false)
            ->assertSee('Send anything. Move anything.')
            ->assertSee('What do you want to do?')
            ->html();
        $this->assertSame(['parcel', 'truck', 'shift'], $this->buttons($html), 'no 3-wheeler button while it is not priced');

        $this->price('three_wheeler');
        $this->assertSame(['parcel', 'three_wheeler', 'truck', 'shift'], $this->buttons($this->page($w)->html()));
    }

    public function test_shift_house_picks_the_truck_house_shifting_and_two_helpers(): void
    {
        $w = $this->world();
        $this->price('mini_truck');

        $this->page($w)
            ->call('choose', 'shift')
            ->assertSet('want', 'shift')
            ->assertSet('vehicle', 'mini_truck')
            ->assertSet('packageContents', 'house_shifting')
            ->assertSet('helpers', ParcelSettings::HELPERS_MAX)
            ->assertSee('What are you moving?')
            ->assertSee('data-testid="helpers-picker"', false)
            ->assertSeeHtml('data-move="shift" aria-pressed="true"');
    }

    public function test_shift_house_asks_for_no_helpers_where_the_helper_price_is_not_set(): void
    {
        $w = $this->world();
        $this->price('mini_truck', helpers: false);

        $this->page($w)->call('choose', 'shift')
            ->assertSet('vehicle', 'mini_truck')
            ->assertSet('helpers', 0)
            ->assertDontSee('data-testid="helpers-picker"', false);
    }

    public function test_a_button_not_offered_here_is_ignored(): void
    {
        $w = $this->world();
        $this->price('mini_truck');

        $this->page($w)->call('choose', 'three_wheeler')
            ->assertSet('want', '')
            ->assertSet('vehicle', 'two_wheeler');
    }

    public function test_book_a_truck_shows_only_the_truck_sizes_and_back_to_parcel_hides_them(): void
    {
        $w = $this->world();
        $this->price('three_wheeler');
        $this->price('mini_truck');
        $this->price('pickup');

        $this->page($w)
            ->assertDontSee('data-testid="vehicle-picker"', false)
            ->call('choose', 'truck')
            ->assertSet('vehicle', 'mini_truck')
            ->assertSee('Truck size')
            ->assertSee('Pickup truck')
            ->assertSeeHtml('value="pickup"')->assertDontSeeHtml('value="two_wheeler"')->assertDontSeeHtml('value="three_wheeler"')
            ->set('vehicle', 'pickup')
            ->call('choose', 'shift')
            ->assertSet('vehicle', 'pickup', 'the truck size chosen stays for house shifting')
            ->call('choose', 'parcel')
            ->assertSet('vehicle', 'two_wheeler')
            ->assertSet('packageContents', '')
            ->assertSet('helpers', 0)
            ->assertDontSee('data-testid="vehicle-picker"', false)
            ->assertSee('Size');
    }

    public function test_a_link_opens_the_page_on_its_button(): void
    {
        $w = $this->world();
        $this->price('mini_truck');

        $this->page($w, ['want' => 'shift'])
            ->assertSet('vehicle', 'mini_truck')
            ->assertSet('packageContents', 'house_shifting');

        // A link to a vehicle that is not priced opens on parcels.
        $this->page($w, ['want' => 'three_wheeler'])->assertSet('vehicle', 'two_wheeler')->assertSet('want', '');
    }

    public function test_a_customer_books_house_shifting_from_the_big_button(): void
    {
        $w = $this->world();
        $this->price('mini_truck');

        $this->page($w)
            ->call('choose', 'shift')
            ->set('recipientPhone', '9876543210')->set('paymentMethod', 'cash')->set('restrictedConfirmed', true)
            ->call('submit')
            ->assertSet('error', '');

        $order = ParcelOrder::sole();
        $this->assertSame('mini_truck', $order->vehicle_type);
        $this->assertSame(2, $order->helpers);
        $this->assertSame('house_shifting', $order->package_contents);
    }

    public function test_the_brand_words_are_the_super_admins(): void
    {
        $w = $this->world();
        $this->price('mini_truck');
        Setting::set('text.move.brand.en', 'MoveIt');

        $this->page($w)->assertSee('MoveIt');
    }
}

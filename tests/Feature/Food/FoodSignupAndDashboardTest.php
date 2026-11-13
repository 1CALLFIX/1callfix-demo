<?php

namespace Tests\Feature\Food;

use App\Livewire\FoodOrders\Dashboard;
use App\Livewire\Partner\ApplyForm;
use App\Livewire\Provider\Auth\Register;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceReturn;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/**
 * Food F5 (2026-11-03) — restaurants join through /partners → the one partner sign-up (owner rule: one join path),
 * opened by the Food Settings switch "Take restaurant sign-ups"; the FSSAI number is asked for and the restaurant
 * waits for approval. The Food Dashboard counts only food orders inside the viewer's scope.
 */
class FoodSignupAndDashboardTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    private $franchise;
    private $zone;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->bindFakeFirebase();
        [, , $this->franchise, $this->zone] = $this->makeFranchiseTree();
        $this->zone->update(['center_lat' => 14.44, 'center_lng' => 79.98]);
        $this->activateMarketplaceModuleFor($this->franchise, Modules::FOOD);
    }

    private function apply(string $phone): string
    {
        return Livewire::test(ApplyForm::class)->set('role', 'food')->set('name', 'Ravi')->set('phone', $phone)
            ->set('cityChoice', '__other')->set('city', 'Nellore')->set('consent', true)->call('submit')->get('outcome');
    }

    /** Owner 2026-11-13: before Food is live, a waitlist until restaurant sign-ups open early; once live, Apply now. */
    public function test_restaurant_card_is_a_waiting_list_until_food_is_live_or_sign_ups_open_early(): void
    {
        \Illuminate\Support\Facades\DB::table('module_activations')->where('module_id', \App\Models\Module::where('code', 'food')->value('id'))->update(['is_active' => false]);
        $this->assertSame('waitlist', $this->apply('9876500021'));

        Setting::set('food.recruiting_restaurants', '1');
        $this->assertSame('handoff', $this->apply('9876500022'));

        Setting::set('food.recruiting_restaurants', '0');
        \Illuminate\Support\Facades\DB::table('module_activations')->where('module_id', \App\Models\Module::where('code', 'food')->value('id'))->update(['is_active' => true]);
        $this->assertSame('handoff', $this->apply('9876500024'), 'Food is live: restaurants apply at once.');
    }


    public function test_a_new_restaurant_signs_up_with_its_fssai_number_and_waits_for_approval(): void
    {
        Setting::set('food.recruiting_restaurants', '1');
        $this->assertSame('handoff', $this->apply('9876500023'));

        $c = Livewire::test(Register::class)->assertSet('partnerRole', 'food')
            ->assertSee('Add your restaurant to')
            ->set('phone', '9876500023')->call('requestPhoneCode')
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164('9876500023')))->assertSet('step', 'details');
        $c->call('useCurrentLocationForNewAddress', 14.44, 79.98)
            ->set('name', 'Ravi')->set('password', 'food-pass-1')->set('password_confirmation', 'food-pass-1')
            ->set('address', '5 Trunk Road, Nellore')->set('terms', true)->set('storeName', 'Ravi Tiffins')
            ->set('documents.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('documents.address_proof', UploadedFile::fake()->image('addr.png'))
            ->set('documents.bank_details', UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'))
            ->set('fssaiNumber', '12345')->call('submitApplication')->assertHasErrors(['fssaiNumber' => 'regex'])
            ->set('fssaiNumber', '12345678901234')->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true)
            ->assertSee('menu and opening hours');

        $store = Store::where('name', 'Ravi Tiffins')->sole();
        $user = User::where('phone', '9876500023')->sole();
        $this->assertSame(Modules::FOOD, $store->module);
        $this->assertSame('12345678901234', $store->fssai_number);
        $this->assertSame('pending', $store->approval_status);
        $this->assertFalse($store->is_active);
        $this->assertTrue($user->holdsPartnerRole('food'));
        $this->assertTrue($user->holdsPartnerRole('commerce'), 'the same seller portal');
        $this->assertFalse($user->fresh()->canApplyAsPartner('food'), 'one restaurant per account through sign-up');
    }

    public function test_the_fssai_number_is_optional_at_sign_up_by_default_and_the_approval_check_still_applies(): void
    {
        Setting::set('food.recruiting_restaurants', '1');
        $this->apply('9876500024');

        $c = Livewire::test(Register::class)->set('phone', '9876500024')->call('requestPhoneCode')
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164('9876500024')));
        $c->call('useCurrentLocationForNewAddress', 14.44, 79.98)
            ->set('name', 'Sita')->set('password', 'food-pass-1')->set('password_confirmation', 'food-pass-1')
            ->set('address', '9 Market Road, Nellore')->set('terms', true)->set('storeName', 'Sita Mess')
            ->set('documents.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('documents.address_proof', UploadedFile::fake()->image('addr.png'))
            ->set('documents.bank_details', UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'))
            ->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true);

        $store = Store::where('name', 'Sita Mess')->sole();
        $this->assertNull($store->fssai_number);
        $this->assertTrue($store->missingFssai(), 'not approved or shown to customers until the number is added');
    }

    public function test_super_admin_can_make_the_fssai_number_required_at_sign_up(): void
    {
        Setting::set('food.recruiting_restaurants', '1');
        Setting::set('food.fssai_at_signup', '1');
        $this->apply('9876500025');

        $c = Livewire::test(Register::class)->set('phone', '9876500025')->call('requestPhoneCode')
            ->call('phoneTokenReceived', $this->firebase->issuePhoneToken($this->e164('9876500025')));
        $c->call('useCurrentLocationForNewAddress', 14.44, 79.98)
            ->set('name', 'Gopi')->set('password', 'food-pass-1')->set('password_confirmation', 'food-pass-1')
            ->set('address', '3 Bazaar Street, Nellore')->set('terms', true)->set('storeName', 'Gopi Biryani')
            ->set('documents.id_proof', UploadedFile::fake()->image('id.jpg'))
            ->set('documents.address_proof', UploadedFile::fake()->image('addr.png'))
            ->set('documents.bank_details', UploadedFile::fake()->create('bank.pdf', 120, 'application/pdf'))
            ->call('submitApplication')->assertHasErrors(['fssaiNumber' => 'required'])
            ->set('fssaiNumber', '12345678901234')->call('submitApplication')->assertHasNoErrors()->assertSet('submitted', true);
    }

    public function test_the_dashboard_counts_food_orders_waiting_late_problems_and_strikes(): void
    {
        $restaurant = $this->makeStore($this->franchise, $this->zone, null, ['module' => Modules::FOOD, 'name' => 'Annapurna', 'fssai_number' => '12345678901234']);
        $shop = $this->makeStore($this->franchise, $this->zone, null, ['name' => 'Bottle Shop']);
        $customer = $this->makeCustomer();
        $make = fn (Store $store, array $extra) => MarketplaceOrder::create(array_merge([
            'code' => 'FD-'.random_int(100000, 999999), 'franchise_id' => $this->franchise->id, 'zone_id' => $this->zone->id,
            'customer_id' => $customer->id, 'store_id' => $store->id, 'module' => $store->module, 'order_type' => 'delivery',
            'subtotal' => 100, 'total_amount' => 100, 'status' => 'pending', 'payment_status' => 'paid', 'payment_method' => 'online',
        ], $extra));

        $make($restaurant, ['accept_by' => now()->addMinutes(5)]);
        $make($restaurant, ['status' => 'preparing', 'ready_by' => now()->subMinutes(12)]);
        $make($restaurant, ['status' => 'cancelled', 'cancellation_note' => 'The restaurant did not accept the order within 10 minutes']);
        $done = $make($restaurant, ['status' => 'completed', 'completed_at' => now()]);
        $make($shop, []); // a shop order is never counted
        $rider = $this->makeParcelRiderIn($this->franchise, $this->zone);
        MarketplaceReturn::create([
            'marketplace_order_id' => $done->id, 'franchise_id' => $this->franchise->id, 'store_id' => $restaurant->id, 'customer_id' => $customer->id,
            'reason' => 'spilled', 'description' => 'Spilled', 'items' => [], 'photos' => [], 'status' => MarketplaceReturn::RECEIVED, 'refund_amount' => 50,
            'fault' => MarketplaceReturn::FAULT_TRANSIT, 'field_worker_id' => $rider->id, 'decided_at' => now(),
        ]);

        Livewire::actingAs($this->makeSuperAdmin())->test(Dashboard::class)
            ->assertViewHas('todayTotal', 4)
            ->assertViewHas('notAccepted', 1)
            ->assertViewHas('waitingAccept', fn ($rows) => $rows->count() === 1)
            ->assertViewHas('late', fn ($rows) => $rows->count() === 1)
            ->assertViewHas('restaurantsLive', 1)
            ->assertViewHas('problemsOpen', 1)
            ->assertViewHas('strikes', fn ($rows) => $rows->count() === 1 && (int) $rows->first()->n === 1)
            ->assertSee('Food Dashboard')
            ->assertSee('min late');
    }

    public function test_the_dashboard_needs_the_orders_permission(): void
    {
        $this->actingAs($this->makeCustomer())->get(route('admin.food.dashboard'))->assertForbidden();
    }
}

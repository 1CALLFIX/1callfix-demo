<?php

namespace Tests\Feature\Parcel;

use App\Livewire\Customer\Parcel\Create;
use App\Livewire\Customer\Parcel\Show;
use App\Livewire\ParcelOrders\Manage as AdminParcelOrders;
use App\Livewire\ParcelOrders\Settings as AdminParcelSettings;
use App\Livewire\Rider\Auth\Login as RiderLogin;
use App\Livewire\Rider\Dashboard as RiderDashboard;
use App\Livewire\Rider\Earnings as RiderEarnings;
use App\Models\ActivityLog;
use App\Models\Address;
use App\Models\DispatchAttempt;
use App\Models\FieldWorker;
use App\Models\ParcelOrder;
use App\Models\Setting;
use App\Models\User;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Parcel delivery completion — the web surfaces: customer Send a Parcel / tracking / list, the rider area,
 * the admin Parcel Settings screen and manual assignment, and the Modules switch hiding all of it.
 */
class ParcelWebPagesTest extends TestCase
{
    use RefreshDatabase;
    use BookingFixtureHelpers;
    use ParcelOrderFixtureHelpers;

    private function world(bool $active = true): array
    {
        [$country, $city, $franchise, $zone] = $this->makeFranchiseTree();
        if ($active) {
            $this->activateParcelFor($franchise);
        } else {
            $this->configureParcelPricing();
        }
        $customer = $this->makeCustomer();
        [$pickup, $dropoff] = $this->makeParcelAddresses($franchise, $zone, $customer);
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        return compact('franchise', 'zone', 'customer', 'pickup', 'dropoff', 'rider');
    }

    private function superAdmin(): User
    {
        $u = $this->makeCustomer();
        $u->forceFill(['role' => 'super_admin'])->save();

        return $u;
    }

    // ═══════════════════════ customer ═══════════════════════

    public function test_send_page_exists_only_while_parcel_is_on_somewhere(): void
    {
        $w = $this->world(active: false);

        $this->actingAs($w['customer'])->get('/parcel')->assertNotFound();

        app(ModuleActivationService::class)->setActive(Modules::PARCEL, 'franchise', $w['franchise']->id, true);
        $this->actingAs($w['customer'])->get('/parcel')->assertOk()->assertSee('Send a parcel');
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->world();

        $this->get('/parcel')->assertRedirect();
    }

    public function test_customer_books_a_cash_parcel_to_a_searched_drop_point(): void
    {
        Queue::fake();
        $w = $this->world();

        Livewire::actingAs($w['customer'])->test(Create::class)->set('restrictedConfirmed', true)
            ->set('pickupMode', 'saved')
            ->set('pickupAddressId', $w['pickup']->id)
            ->call('setDropPin', 1.01, 1.01, 'Lake View Road')
            ->set('dropDetail', 'Flat 4B')
            ->set('recipientName', 'Ravi')
            ->set('recipientPhone', '9876543210')
            ->set('packageSize', 'small')
            ->set('paymentMethod', 'cash')
            ->assertSee('Total')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect();

        $order = ParcelOrder::firstOrFail();
        $this->assertSame($w['customer']->id, $order->customer_id);
        $this->assertSame($w['pickup']->id, $order->pickup_address_id);
        $this->assertSame('Ravi', $order->recipient_name);
        $this->assertSame('cash', $order->payment_method);
        $this->assertSame($w['zone']->id, $order->zone_id, 'Zone comes from the pickup, never the client.');

        $drop = Address::findOrFail($order->dropoff_address_id);
        $this->assertTrue($drop->is_parcel_only);
        $this->assertSame('Flat 4B, Lake View Road', $drop->address_line);
    }

    /** Owner 2026-10-24: "2 or 3 taps". Home tile → "Send again" chip → Book now. Everything else is pre-filled. */
    public function test_a_repeat_parcel_books_in_two_taps_after_opening_the_page(): void
    {
        Queue::fake();
        $w = $this->world();
        $previous = ParcelOrder::create([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_name' => 'Amma', 'recipient_phone' => '9000000001', 'package_size' => 'medium',
            'status' => 'delivered', 'price_quoted' => 60, 'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);

        $page = Livewire::actingAs($w['customer'])->test(Create::class)->set('restrictedConfirmed', true)
            ->assertSee('Send again')
            ->assertSee('Amma')
            ->assertSet('packageSize', 'medium')      // last size remembered
            ->assertSet('paymentMethod', 'cash')      // last payment method remembered
            ->assertSet('pickupAddressId', $w['pickup']->id); // default pickup, no tap

        $page->call('useRecent', $previous->id)      // tap 1
            ->assertSet('recipientPhone', '9000000001')
            ->assertSee('Book now')
            ->call('submit')                          // tap 2
            ->assertHasNoErrors()
            ->assertRedirect();

        $order = ParcelOrder::latest('id')->firstOrFail();
        $this->assertNotSame($previous->id, $order->id);
        $this->assertSame('Amma', $order->recipient_name);
        $this->assertSame('medium', $order->package_size);
        $this->assertNotSame($previous->dropoff_address_id, $order->dropoff_address_id, 'The pin is copied, never shared.');
        $this->assertEquals((float) $w['dropoff']->lat, (float) $order->dropoffAddress->lat);
    }

    public function test_send_again_only_offers_the_customers_own_parcels(): void
    {
        $w = $this->world();
        $other = $this->makeCustomer();
        $theirs = ParcelOrder::create([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $other->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'recipient_name' => 'Secret', 'recipient_phone' => '9000000002',
            'status' => 'delivered', 'price_quoted' => 60, 'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);

        Livewire::actingAs($w['customer'])->test(Create::class)->set('restrictedConfirmed', true)
            ->assertDontSee('Secret')
            ->call('useRecent', $theirs->id)
            ->assertSet('recipientPhone', '')
            ->assertSet('dropLat', null);
    }

    public function test_a_picked_contact_fills_name_and_a_ten_digit_number(): void
    {
        $w = $this->world();

        Livewire::actingAs($w['customer'])->test(Create::class)->set('restrictedConfirmed', true)
            ->call('setRecipient', 'Ravi Kumar', '+91 98765-43210')
            ->assertSet('recipientName', 'Ravi Kumar')
            ->assertSet('recipientPhone', '9876543210');
    }

    public function test_recipient_name_is_optional(): void
    {
        Queue::fake();
        $w = $this->world();

        Livewire::actingAs($w['customer'])->test(Create::class)->set('restrictedConfirmed', true)
            ->call('setDropPin', 1.01, 1.01, 'Lake View Road')
            ->set('recipientPhone', '9876543210')
            ->set('paymentMethod', 'cash')
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertNull(ParcelOrder::firstOrFail()->recipient_name);
    }

    public function test_an_online_parcel_opens_razorpay_after_booking(): void
    {
        Queue::fake();
        $gateway = $this->mock(\App\Contracts\PaymentGateway::class);
        $gateway->shouldReceive('isConfigured')->andReturnTrue();
        $gateway->shouldReceive('identifier')->andReturn('razorpay');
        $gateway->shouldReceive('createRawOrder')->once()->andReturn(['razorpay_order_id' => 'order_W1', 'amount' => 4000, 'currency' => 'INR', 'key_id' => 'rzp_test']);
        $w = $this->world();

        Livewire::actingAs($w['customer'])->test(Create::class)->set('restrictedConfirmed', true)
            ->set('pickupAddressId', $w['pickup']->id)
            ->call('setDropPin', 1.01, 1.01, 'Lake View Road')
            ->set('recipientName', 'Ravi')
            ->set('recipientPhone', '9876543210')
            ->set('paymentMethod', 'online')
            ->call('submit')
            ->assertDispatched('razorpay-open');

        $this->assertTrue(ParcelOrder::firstOrFail()->isAwaitingOnlinePayment());
    }

    public function test_a_pickup_outside_an_active_zone_is_refused(): void
    {
        $w = $this->world();
        [, , $otherFranchise, $otherZone] = $this->makeFranchiseTree(); // parcel not active here
        $elsewhere = $this->makeAddress($w['customer'], $otherFranchise, $otherZone);

        Livewire::actingAs($w['customer'])->test(Create::class)->set('restrictedConfirmed', true)
            ->set('pickupAddressId', $elsewhere->id)
            ->call('setDropPin', 1.01, 1.01, 'Lake View Road')
            ->set('recipientName', 'Ravi')
            ->set('recipientPhone', '9876543210')
            ->set('paymentMethod', 'cash')
            ->call('submit')
            ->assertSet('error', 'Parcel delivery is not available from this pickup location yet.');

        $this->assertSame(0, ParcelOrder::count());
    }

    public function test_tracking_page_shows_the_codes_and_is_private_to_the_sender(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();

        $this->actingAs($scenario['customer'])->get('/parcels/'.$scenario['order']->id)
            ->assertOk()->assertSee('1234')->assertSee('5678');

        $this->actingAs($this->makeCustomer())->get('/parcels/'.$scenario['order']->id)->assertNotFound();
    }

    public function test_customer_cancels_from_the_tracking_page(): void
    {
        $w = $this->world();
        $order = ParcelOrder::create([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'status' => 'searching_worker', 'price_quoted' => 60, 'payment_method' => 'cash', 'payment_status' => 'pending',
        ]);

        Livewire::actingAs($w['customer'])->test(Show::class, ['parcelOrder' => $order])
            ->set('confirmingCancel', true)
            ->set('cancelReason', 'Plans changed')
            ->call('cancel')
            ->assertSet('error', '');

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_home_shows_the_parcel_tile_only_where_parcel_is_active(): void
    {
        $w = $this->world(active: false);
        app(\App\Services\Customer\CustomerLocationContext::class)->setZone($w['zone']->id);

        $this->get('/')->assertOk()->assertDontSee('Send a parcel');

        app(ModuleActivationService::class)->setActive(Modules::PARCEL, 'franchise', $w['franchise']->id, true);
        $this->get('/')->assertOk()->assertSee('Send a parcel');
    }

    // ═══════════════════════ rider ═══════════════════════

    public function test_rider_area_is_only_for_parcel_riders(): void
    {
        $w = $this->world();

        $this->actingAs($w['customer'])->get('/rider')->assertForbidden();
        $this->actingAs($w['rider']->user)->get('/rider')->assertOk()->assertSee('Rider');
        $this->actingAs($w['rider']->user)->get('/rider/history')->assertOk();
        $this->actingAs($w['rider']->user)->get('/rider/earnings')->assertOk();
    }

    public function test_rider_signs_in(): void
    {
        $w = $this->world();
        $w['rider']->user->forceFill(['password' => Hash::make('secret-pass-1')])->save();

        Livewire::test(RiderLogin::class)
            ->set('identifier', $w['rider']->user->phone)
            ->set('password', 'secret-pass-1')
            ->call('login')
            ->assertRedirect(route('rider.dashboard'));

        $this->assertAuthenticatedAs($w['rider']->user);
    }

    public function test_a_customer_cannot_sign_in_as_a_rider(): void
    {
        $w = $this->world();
        $w['customer']->forceFill(['password' => Hash::make('secret-pass-1')])->save();

        Livewire::test(RiderLogin::class)
            ->set('identifier', $w['customer']->phone)
            ->set('password', 'secret-pass-1')
            ->call('login')
            ->assertSet('error', 'That account is not an active parcel rider.');
    }

    public function test_rider_goes_online_accepts_picks_up_and_delivers_from_the_dashboard(): void
    {
        $w = $this->world();
        $w['rider']->update(['is_online' => false]);
        $order = ParcelOrder::create([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id, 'recipient_name' => 'Ravi',
            'status' => 'searching_worker', 'price_quoted' => 60, 'payment_method' => 'cash', 'payment_status' => 'pending',
        ]);

        $page = Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)
            ->call('goOnline', 1.0, 1.0);
        $this->assertTrue($w['rider']->fresh()->is_online);
        $this->assertNotNull($w['rider']->fresh()->location_updated_at);

        DispatchAttempt::create(['dispatchable_type' => ParcelOrder::class, 'dispatchable_id' => $order->id, 'notifiable_type' => FieldWorker::class, 'notifiable_id' => $w['rider']->id, 'status' => 'notified', 'notified_at' => now()]);

        $page->call('$refresh')
            ->assertSee($order->code)
            ->assertDispatched('provider-alert-offers')
            ->call('accept', $order->id)
            ->assertSet('error', '');

        $order->refresh();
        $this->assertSame('assigned', $order->status);

        $page->call('onTheWay', $order->id)
            ->assertSee('Collect')
            ->set('pickupOtp', $order->pickup_otp)
            ->call('confirmPickup', $order->id)
            ->assertSet('error', '');
        $this->assertSame('picked_up', $order->fresh()->status);

        $page->set('deliveryOtp', '0000')->call('confirmDelivery', $order->id)->assertSet('error', 'Incorrect delivery OTP.');
        $page->set('deliveryOtp', $order->delivery_otp)->call('confirmDelivery', $order->id)->assertSet('error', '');
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_a_rider_cannot_act_on_another_riders_job(): void
    {
        $scenario = $this->makeAssignedParcelOrderScenario();
        $other = $this->makeParcelRiderIn($scenario['franchise'], $scenario['zone']);

        Livewire::actingAs($other->user)->test(RiderDashboard::class)
            ->call('confirmPickup', $scenario['order']->id)
            ->assertNotFound();
    }

    public function test_rider_dashboard_says_when_parcel_is_switched_off(): void
    {
        $w = $this->world();
        app(ModuleActivationService::class)->setActive(Modules::PARCEL, 'franchise', $w['franchise']->id, false);

        Livewire::actingAs($w['rider']->user)->test(RiderDashboard::class)->assertSee('switched off in your area');
    }

    public function test_rider_settles_cash_dues_from_the_wallet(): void
    {
        $w = $this->world();
        $order = ParcelOrder::create([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'status' => 'delivered', 'price_quoted' => 60, 'payment_method' => 'cash', 'payment_status' => 'paid', 'assigned_worker_id' => $w['rider']->id,
        ]);
        \App\Models\FieldWorkerCashDue::create([
            'field_worker_id' => $w['rider']->id, 'parcel_order_id' => $order->id,
            'commission_id' => \App\Models\Commission::create(['parcel_order_id' => $order->id, 'provider_commission' => 45, 'franchise_commission' => 0, 'platform_commission' => 15])->id,
            'platform_portion' => 15, 'franchise_portion' => 0, 'amount_owed' => 15, 'status' => 'outstanding',
        ]);
        \App\Models\Wallet::create(['user_id' => $w['rider']->user_id, 'balance' => 50]);

        Livewire::actingAs($w['rider']->user)->test(RiderEarnings::class)
            ->assertSee('15.00')
            ->call('settle')
            ->assertSee('Settled');

        $this->assertSame('settled', \App\Models\FieldWorkerCashDue::first()->status);
    }

    // ═══════════════════════ admin ═══════════════════════

    public function test_parcel_settings_screen_is_super_admin_only_and_audits_changes(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get('/admin/parcel-settings')->assertOk()->assertSee('Parcel Settings');
        $this->actingAs($this->makeCustomer())->get('/admin/parcel-settings')->assertForbidden();

        Livewire::actingAs($admin)->test(AdminParcelSettings::class)
            ->set('inputs.parcel__base_fare', '45')
            ->set('inputs.parcel__base_km', '3')
            ->set('inputs.parcel__per_km_rate', '12')
            ->set('inputs.parcel__max_distance_km', '25')
            ->set('inputs.parcel__max_weight_kg', '15')
            ->call('save')
            ->assertSet('flashType', 'success')
            ->set('previewKm', '5')
            ->assertSeeHtml('data-testid="parcel-price-preview"')
            ->assertSee('69.00'); // 45 + (5 - 3) × 12

        $this->assertSame('45', Setting::get('parcel.base_fare'));
        $this->assertTrue(ActivityLog::where('description', 'Setting parcel.base_fare changed')->where('causer_id', $admin->id)->exists());
    }

    public function test_parcel_settings_reject_invalid_values_and_save_nothing(): void
    {
        Livewire::actingAs($this->superAdmin())->test(AdminParcelSettings::class)
            ->set('inputs.parcel__base_fare', '-5')
            ->set('inputs.parcel__per_km_rate', 'abc')
            ->call('save')
            ->assertHasErrors(['inputs.parcel__base_fare', 'inputs.parcel__per_km_rate'])
            ->assertSet('flashType', 'error');

        $this->assertNull(Setting::get('parcel.base_fare'));
    }

    // ═══════════════════════ riders before activation (owner rule 2026-10-24) ═══════════════════════

    public function test_parcel_cannot_be_activated_before_a_rider_is_onboarded(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->configureParcelPricing();

        Livewire::actingAs($this->superAdmin())->test(\App\Livewire\Modules\Manage::class)
            ->set('scopeLevel', 'franchise')
            ->set('scopeId', $franchise->id)
            ->assertSee('0 riders onboarded')
            ->call('toggle', Modules::PARCEL)
            ->assertSet('activationError', fn ($e) => str_contains($e, 'No parcel rider is approved'));

        $this->assertFalse(app(ModuleActivationService::class)->isActiveAnywhere(Modules::PARCEL));
    }

    public function test_parcel_cannot_be_activated_while_prices_are_unset(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->makeParcelRiderIn($franchise, $zone);

        Livewire::actingAs($this->superAdmin())->test(\App\Livewire\Modules\Manage::class)
            ->set('scopeLevel', 'franchise')
            ->set('scopeId', $franchise->id)
            ->call('toggle', Modules::PARCEL)
            ->assertSet('activationError', fn ($e) => str_contains($e, 'Set the parcel prices first'));

        $this->assertFalse(app(ModuleActivationService::class)->isActiveAnywhere(Modules::PARCEL));
    }

    public function test_a_suspended_or_unapproved_rider_does_not_count(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->configureParcelPricing();
        $pending = $this->makeParcelRiderIn($franchise, $zone);
        $pending->update(['kyc_status' => 'pending']);
        $suspended = $this->makeParcelRiderIn($franchise, $zone);
        $suspended->user->forceFill(['status' => 'suspended'])->save();

        $this->assertSame(0, app(\App\Services\Parcel\ParcelReadiness::class)->riderCount('franchise', $franchise->id));
    }

    public function test_parcel_activates_once_ready_and_can_always_be_switched_off(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $this->configureParcelPricing();
        $rider = $this->makeParcelRiderIn($franchise, $zone);

        $page = Livewire::actingAs($this->superAdmin())->test(\App\Livewire\Modules\Manage::class)
            ->set('scopeLevel', 'franchise')
            ->set('scopeId', $franchise->id)
            ->assertSee('1 rider onboarded')
            ->assertSee('ready to activate')
            ->call('toggle', Modules::PARCEL)
            ->assertSet('activationError', '');
        $this->assertTrue(app(ModuleActivationService::class)->isActiveAnywhere(Modules::PARCEL));

        // The only rider leaves: switching OFF is never blocked.
        $rider->update(['is_active' => false]);
        $page->call('toggle', Modules::PARCEL)->assertSet('activationError', '');
        $this->assertFalse(app(ModuleActivationService::class)->isActiveAnywhere(Modules::PARCEL));
    }

    public function test_admin_adds_a_parcel_rider_who_can_then_sign_in(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();

        Livewire::actingAs($this->superAdmin())->test(\App\Livewire\Workers\Index::class)
            ->set('showAddRider', true)
            ->set('riderName', 'Suresh')
            ->set('riderPhone', '9123456780')
            ->set('riderPassword', 'rider-pass-1')
            ->set('riderZoneId', $zone->id)
            ->call('addRider')
            ->assertHasErrors(['riderVerified'])
            ->set('riderVerified', true)
            ->call('addRider')
            ->assertHasNoErrors();

        $worker = FieldWorker::with('user')->firstOrFail();
        $this->assertSame('Suresh', $worker->user->name);
        $this->assertSame('approved', $worker->kyc_status);
        $this->assertSame($zone->id, $worker->zone_id);
        $this->assertTrue($worker->hasCapability('parcel_rider'));
        $this->assertTrue(ActivityLog::where('subject_type', FieldWorker::class)->where('subject_id', $worker->id)->exists());

        Livewire::test(RiderLogin::class)
            ->set('identifier', '9123456780')
            ->set('password', 'rider-pass-1')
            ->call('login')
            ->assertRedirect(route('rider.dashboard'));
    }

    public function test_adding_a_rider_links_an_existing_account_without_touching_its_password(): void
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $existing = $this->makeCustomer();
        $existing->forceFill(['password' => Hash::make('own-pass-123')])->save();

        Livewire::actingAs($this->superAdmin())->test(\App\Livewire\Workers\Index::class)
            ->set('riderName', 'Ignored')
            ->set('riderPhone', $existing->phone)
            ->set('riderZoneId', $zone->id)
            ->set('riderVerified', true)
            ->call('addRider')
            ->assertHasNoErrors();

        $this->assertSame($existing->id, FieldWorker::firstOrFail()->user_id);
        $this->assertTrue(Hash::check('own-pass-123', $existing->fresh()->password));

        // Second time: already a worker.
        Livewire::actingAs($this->superAdmin())->test(\App\Livewire\Workers\Index::class)
            ->set('riderName', 'Again')->set('riderPhone', $existing->phone)->set('riderZoneId', $zone->id)->set('riderVerified', true)
            ->call('addRider')
            ->assertHasErrors(['riderPhone']);
    }

    public function test_admin_assigns_a_rider_from_the_order_screen(): void
    {
        Queue::fake();
        $w = $this->world();
        $order = ParcelOrder::create([
            'franchise_id' => $w['franchise']->id, 'zone_id' => $w['zone']->id, 'customer_id' => $w['customer']->id,
            'pickup_address_id' => $w['pickup']->id, 'dropoff_address_id' => $w['dropoff']->id,
            'status' => 'searching_worker', 'price_quoted' => 60, 'payment_method' => 'cash', 'payment_status' => 'pending',
        ]);

        Livewire::actingAs($this->superAdmin())->test(AdminParcelOrders::class)
            ->call('viewOrder', $order->id)
            ->assertSee('Assign a rider')
            ->set('assignWorkerId', $w['rider']->id)
            ->call('assignRider')
            ->assertHasNoErrors();

        $this->assertSame($w['rider']->id, $order->fresh()->assigned_worker_id);
    }
}

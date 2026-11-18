<?php

namespace Tests\Feature\Vehicles;

use App\Actions\RegisterVehicleOwnerAction;
use App\Livewire\Customer\Vehicles\Contact;
use App\Livewire\Halls\Settings as RentalSettingsPage;
use App\Livewire\Venue\VehicleEditor;
use App\Livewire\VehicleListings\Manage as VehiclesManage;
use App\Models\Franchise;
use App\Models\Setting;
use App\Models\User;
use App\Models\VehicleListing;
use App\Models\Zone;
use App\Services\Homes\ListingFeeService;
use App\Services\ModuleActivationService;
use App\Support\Modules;
use App\Support\PartnerPage\PartnerPageData;
use App\Support\VehicleListingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Vehicles for rent (owner 2026-11-18, "OLX-style listing"). A vehicle owner joins through /partners ("Vehicle owner"),
 * finishes the vehicle in the Rental portal and sends it for review; the team approves it; customers in the city browse
 * with tap filters and see the owner's number (Call / WhatsApp). No booking or payment runs between them: the platform
 * earns the listing fee. Every list is a Rental Setting that a franchise or zone can change.
 */
class VehicleListingTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;
    use RbacTestHelpers;

    private $city;
    private Franchise $franchise;
    private Zone $zone;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $this->city->id)->firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);
        Setting::set('rental.kind.vehicles', '1');
        $this->customer = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Asha', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
    }

    private function draft(): VehicleListing
    {
        return app(RegisterVehicleOwnerAction::class)->execute(
            zone: $this->zone, existing: null, registration: ['address' => '4-5 Trunk Road, Nellore', 'lat' => '14.44', 'lng' => '79.98'],
            name: 'Ravi', nationalPhone: '9'.fake()->numerify('#########'), plainPassword: 'secret-pass-1',
        );
    }

    private function fill($page)
    {
        return $page->set('kind', 'Car')->set('make', 'Maruti')->set('model', 'Swift')->set('fuel', 'Petrol')->set('transmission', 'Manual')
            ->call('pickSeats', 5)->set('driverOptions', ['Self-drive', 'With driver'])->set('priceDay', '1800')->set('priceHour', '250')
            ->set('deposit', '5000')->set('kmPerDay', '250')->set('zoneId', (string) $this->zone->id)
            ->set('addressLine', '4-5 Trunk Road, Nellore')->set('landmark', 'Near RTC bus stand')
            ->set('features', ['Air conditioner', 'Fastag'])->set('ruleList', ['Driving licence needed', 'No smoking']);
    }

    private function photos($page)
    {
        return $page->set('cover', UploadedFile::fake()->image('cover.jpg', 1600, 1067))
            ->set('newPhotos', [UploadedFile::fake()->image('a.jpg', 1600, 1067), UploadedFile::fake()->image('b.jpg', 1600, 1067)]);
    }

    /** A live, approved vehicle in the test city. */
    private function liveVehicle(array $extra = []): VehicleListing
    {
        Storage::fake('public');
        $vehicle = $this->draft();
        $owner = $vehicle->provider->user;
        $this->photos($this->fill(Livewire::actingAs($owner)->test(VehicleEditor::class, ['listingId' => $vehicle->id])))->call('submit')->assertHasNoErrors();
        Livewire::actingAs($this->makeSuperAdmin())->test(VehiclesManage::class)->call('approve', $vehicle->id);
        $vehicle->refresh()->update($extra);

        return $vehicle->fresh();
    }

    // ── settings and joining ──

    public function test_lists_are_rental_settings_and_a_zone_can_have_its_own(): void
    {
        $this->assertContains('Tractor', VehicleListingSettings::listIn('vehicles.kinds'));
        Setting::set('vehicles.kinds', "Jeep\nTractor", 'zone', $this->zone->id);
        $this->assertSame(['Jeep', 'Tractor'], VehicleListingSettings::listIn('vehicles.kinds', ['zone_id' => $this->zone->id, 'franchise_id' => $this->franchise->id]));
        $this->assertContains('Car', VehicleListingSettings::listIn('vehicles.kinds', ['franchise_id' => $this->franchise->id]));

        Livewire::actingAs($this->makeSuperAdmin())->test(RentalSettingsPage::class)->assertOk()
            ->assertSee('Vehicles for rent · Choices')->assertSee('Kinds of vehicle')->assertSee('Fee to show a vehicle (each listing period)');
    }

    public function test_vehicle_owner_card_hands_off_only_when_super_admin_takes_vehicle_owners(): void
    {
        $card = fn () => collect(PartnerPageData::roles())->firstWhere('code', 'vehicles');
        $this->assertSame('Vehicle owner', $card()['label']);
        $this->assertSame('Rental', $card()['module']);
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, false);
        $this->assertFalse($card()['hands_off']);
        Setting::set('vehicles.recruiting_owners', '1');
        $this->assertTrue($card()['hands_off']);
        $this->assertNull(collect(PartnerPageData::roles())->firstWhere('code', 'rental'), 'The old vehicle-or-equipment card is equipment only now (off by default).');
        Setting::set('rental.kind.vehicles', '0');
        $this->assertNull($card(), 'Vehicles off: no Vehicle owner card.');
    }

    public function test_sign_up_page_takes_a_vehicles_lead_and_asks_for_vehicle_documents(): void
    {
        Setting::set('vehicles.recruiting_owners', '1');
        $lead = \App\Models\PartnerLead::create(['name' => 'Ravi', 'phone' => '9876543210', 'city' => 'Nellore', 'role' => 'vehicles', 'status' => 'handed_off', 'source' => 'partner_page', 'consent_at' => now(), 'consent_text' => 'ok']);
        session()->put('partner_lead', ['id' => $lead->id, 'role' => 'vehicles', 'city' => '']);

        Livewire::test(\App\Livewire\Provider\Auth\Register::class)
            ->assertSet('partnerRole', 'vehicles')
            ->assertSee('Rent out your vehicle on')->assertSee('Area where the vehicle is parked')->assertSee('Vehicle already listed?');
        $this->assertContains('vehicle_rc', app(\App\Services\Kyc\KycDocumentService::class)->requirementsFor('vehicle', null)->where('is_required', true)->pluck('document_type')->all());
    }

    public function test_owner_signs_up_finishes_the_vehicle_and_sends_it_for_review(): void
    {
        Storage::fake('public');
        $vehicle = $this->draft();
        $owner = $vehicle->provider->user;
        $this->assertTrue($owner->holdsPartnerRole('vehicles'));
        $this->assertFalse($owner->holdsPartnerRole('service'), 'A vehicle-only account is not a service partner.');
        $this->assertFalse($owner->canApplyAsPartner('vehicles'));
        $this->assertSame(['vehicle'], app(\App\Services\Kyc\KycDocumentService::class)->applicableTypesFor($vehicle->provider));
        $this->actingAs($owner)->get(route('venue.dashboard'))->assertOk()->assertSee('Vehicles for rent')->assertSee('Finish your vehicle and send for review');
        $this->actingAs($this->customer)->get(route('venue.dashboard'))->assertForbidden();
        $this->actingAs($owner)->get(route('venue.vehicles.edit', $vehicle->id))->assertOk()->assertSee('Your vehicle')->assertSee('Tractor');

        // Missing photos: kept as a draft with the reason.
        $page = $this->fill(Livewire::actingAs($owner)->test(VehicleEditor::class, ['listingId' => $vehicle->id]))->call('submit');
        $this->assertSame('draft', $vehicle->fresh()->status);
        $page->assertSee('Add a main photo.');

        $this->photos($page)->call('submit')->assertHasNoErrors();
        $vehicle->refresh();
        $this->assertSame('in_review', $vehicle->status);
        $this->assertSame('Maruti Swift car for rent in Nellore Zone', $vehicle->title, 'The title is made from the taps.');
        $this->assertSame('maruti-swift-car-for-rent-in-nellore-zone', $vehicle->slug);
        $this->assertSame(5, $vehicle->seats);
        $this->assertSame(['Self-drive', 'With driver'], $vehicle->driver_options);
        $this->assertCount(2, $vehicle->photos);

        // Waiting for review: locked.
        Livewire::actingAs($owner)->test(VehicleEditor::class, ['listingId' => $vehicle->id])->set('priceDay', '2000')->call('save')->assertSee('We are checking this vehicle');
        $this->assertSame(1800.0, (float) $vehicle->fresh()->price_day);

        // Another owner never opens it.
        $other = $this->draft()->provider->user;
        $this->actingAs($other)->get(route('venue.vehicles.edit', $vehicle->id))->assertNotFound();
    }

    public function test_price_out_of_the_settings_range_and_choices_not_on_the_list_are_refused(): void
    {
        $vehicle = $this->draft();
        $page = $this->fill(Livewire::actingAs($vehicle->provider->user)->test(VehicleEditor::class, ['listingId' => $vehicle->id]));
        $page->set('priceDay', '50')->call('save')->assertHasErrors(['priceDay']);
        $page->set('priceDay', '1800')->set('kind', 'Spaceship')->call('save')->assertHasErrors(['kind']);
        Setting::set('vehicles.kinds', "Car\nSpaceship", 'zone', $this->zone->id);
        $page->call('save')->assertHasNoErrors();
        $page->set('driverOptions', [])->call('save')->assertHasErrors(['driverOptions']);
    }

    // ── admin and the owner's controls ──

    public function test_admin_approves_and_a_live_vehicle_changes_price_at_once_but_photos_wait(): void
    {
        $vehicle = $this->liveVehicle();
        $this->assertSame('approved', $vehicle->status);
        $this->assertTrue($vehicle->isLive());
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $vehicle->expires_at->timestamp, 5, 'Up for Rental Settings\' listing days.');

        $owner = $vehicle->provider->user;
        Livewire::actingAs($owner)->test(VehicleEditor::class, ['listingId' => $vehicle->id])
            ->set('priceDay', '2000')->set('about', 'Serviced last week.')->call('save')->assertHasNoErrors();
        $vehicle->refresh();
        $this->assertSame(2000.0, (float) $vehicle->price_day);
        $this->assertNull($vehicle->about);
        $this->assertSame('Serviced last week.', $vehicle->pending_changes['about']);
        $this->assertSame('maruti-swift-car-for-rent-in-nellore-zone', $vehicle->slug, 'The public link never changes on a live vehicle.');

        Livewire::actingAs($this->makeSuperAdmin())->test(VehiclesManage::class)->call('view', $vehicle->id)->assertSee('Change waiting')->call('approveChanges', $vehicle->id);
        $this->assertSame('Serviced last week.', $vehicle->fresh()->about);
    }

    public function test_not_available_and_expired_vehicles_are_hidden_until_available_again(): void
    {
        $vehicle = $this->liveVehicle();
        $url = route('customer.vehicles.show', [$this->city->slug, $vehicle->slug]);
        $this->get($url)->assertOk();

        Livewire::actingAs($vehicle->provider->user)->test(VehicleEditor::class, ['listingId' => $vehicle->id])->call('notAvailable');
        $this->get($url)->assertNotFound();
        Livewire::actingAs($vehicle->provider->user)->test(VehicleEditor::class, ['listingId' => $vehicle->id])->call('availableAgain');
        $this->get($url)->assertOk();

        $vehicle->update(['expires_at' => now()->subMinute()]);
        $this->get($url)->assertNotFound();
        Livewire::actingAs($vehicle->provider->user)->test(VehicleEditor::class, ['listingId' => $vehicle->id])->call('availableAgain');
        $this->assertTrue($vehicle->fresh()->expires_at->isFuture(), 'Free listing: "Available again" starts a new period.');
        $this->get($url)->assertOk();
    }

    public function test_admin_needs_the_permission_and_sends_back_with_a_reason(): void
    {
        $vehicle = $this->liveVehicle();
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(VehiclesManage::class)->assertForbidden();

        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(VehiclesManage::class)->set('statusFilter', '')->assertSee($vehicle->title);
        Livewire::actingAs($admin)->test(VehiclesManage::class)->call('sendBack', $vehicle->id)->assertHasErrors(['reason'])
            ->set('reason', 'Photos are too dark')->call('sendBack', $vehicle->id);
        $this->assertSame('rejected', $vehicle->fresh()->status);
        $this->actingAs($vehicle->provider->user)->get(route('venue.vehicles.edit', $vehicle->id))->assertSee('Photos are too dark');
        $this->assertTrue(collect(\App\Support\AdminNav::forUser($admin)[0]['rental']['items'])->contains('route', 'admin.vehicle-listings.index'));
    }

    // ── customer pages and the owner's number ──

    public function test_city_list_filters_by_taps_and_the_vehicle_page_never_shows_the_pickup_address(): void
    {
        $car = $this->liveVehicle();
        $tractor = $this->liveVehicle();
        $tractor->update(['vehicle_kind' => 'Tractor', 'fuel' => 'Diesel', 'title' => 'Big tractor', 'driver_options' => ['With driver'], 'price_day' => 900]);

        $this->get(route('customer.vehicles.home', $this->city->slug))->assertOk()
            ->assertSee('Find a vehicle to rent')->assertSee($car->title)->assertSee('Big tractor')->assertSee('₹1,800 / day');
        $this->get(route('customer.vehicles.home', [$this->city->slug, 'kind' => 'Tractor']))->assertDontSee($car->title)->assertSee('Big tractor');
        $this->get(route('customer.vehicles.home', [$this->city->slug, 'how' => 'Self-drive']))->assertSee($car->title)->assertDontSee('Big tractor');
        $this->get(route('customer.vehicles.home', [$this->city->slug, 'fuel' => 'Diesel']))->assertDontSee($car->title)->assertSee('Big tractor');
        $this->get(route('customer.vehicles.home', [$this->city->slug, 'sort' => 'cheap']))->assertSeeInOrder(['Big tractor', $car->title]);

        $this->get(route('customer.vehicles.show', [$this->city->slug, $car->slug]))->assertOk()
            ->assertSee('Self-drive')->assertSee('₹5,000')->assertSee('250 km')->assertSee('Near RTC bus stand')
            ->assertSee('Fastag')->assertSee('Driving licence needed')->assertSee('data-testid="photo-thumbs"', false)
            ->assertSee('Share')->assertDontSee('4-5 Trunk Road');

        $this->get(route('customer.rentals.home', $this->city->slug))->assertOk()->assertSee('data-kind="vehicles"', false)->assertSee($car->title);
        $this->assertSame(2, \App\Livewire\Customer\Rentals\Home::liveCountIn($this->city));
    }

    /**
     * 2026-11-18: one city, two franchises, three zones. Vehicles switched on for ONE zone opens the city pages, and
     * the list, the vehicle page, the count and the owner's number all follow each vehicle's own zone / franchise.
     */
    public function test_a_city_with_several_franchises_and_zones_shows_only_vehicles_where_vehicles_is_on(): void
    {
        $zoneA2 = $this->extraZone($this->franchise, 'Nellore East');
        $franchiseB = $this->extraFranchise($this->city, 'Nellore South Franchise');
        $zoneB = $this->extraZone($franchiseB, 'Nellore South');
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $franchiseB->id, true);

        $a1 = $this->liveVehicle(['title' => 'Zone A1 car']);
        $a2 = $this->liveVehicle(['title' => 'Zone A2 car', 'zone_id' => $zoneA2->id]);
        $b = $this->liveVehicle(['title' => 'Franchise B car', 'zone_id' => $zoneB->id, 'franchise_id' => $franchiseB->id]);
        $list = fn () => $this->get(route('customer.vehicles.home', $this->city->slug));
        $page = fn ($v) => $this->get(route('customer.vehicles.show', [$this->city->slug, $v->slug]));

        // On for zone A1 only.
        Setting::set('rental.kind.vehicles', '0');
        Setting::set('rental.kind.vehicles', '1', 'zone', $this->zone->id);
        $list()->assertOk()->assertSee('Zone A1 car')->assertDontSee('Zone A2 car')->assertDontSee('Franchise B car');
        $page($a1)->assertOk();
        $page($a2)->assertNotFound();
        $page($b)->assertNotFound();
        $this->assertSame(1, \App\Livewire\Customer\Rentals\Home::liveCountIn($this->city));
        $this->get(route('customer.rentals.home', $this->city->slug))->assertOk()->assertSee('data-kind="vehicles"', false);
        Livewire::actingAs($this->customer)->withoutLazyLoading()->test(Contact::class, ['listingId' => $a1->id])
            ->call('showPhone')->assertSee($a1->provider->user->phone);

        // Franchise B switches it on: its zone inherits it; zone A2 stays off.
        Setting::set('rental.kind.vehicles', '1', 'franchise', $franchiseB->id);
        $list()->assertSee('Zone A1 car')->assertSee('Franchise B car')->assertDontSee('Zone A2 car');
        $page($b)->assertOk();

        // Franchise A on, but zone A1 switched off: a zone's own "off" wins.
        Setting::set('rental.kind.vehicles', '1', 'franchise', $this->franchise->id);
        Setting::set('rental.kind.vehicles', '0', 'zone', $this->zone->id);
        $list()->assertDontSee('Zone A1 car')->assertSee('Zone A2 car')->assertSee('Franchise B car');
        $page($a1)->assertNotFound();

        // Off everywhere: the city pages close.
        Setting::set('rental.kind.vehicles', '0', 'franchise', $this->franchise->id);
        Setting::set('rental.kind.vehicles', '0', 'franchise', $franchiseB->id);
        $list()->assertNotFound();
    }

    public function test_pages_are_hidden_while_rental_or_vehicles_is_off(): void
    {
        $vehicle = $this->liveVehicle();
        Setting::set('rental.kind.vehicles', '0');
        $this->get(route('customer.vehicles.home', $this->city->slug))->assertNotFound();
        $this->get(route('customer.vehicles.show', [$this->city->slug, $vehicle->slug]))->assertNotFound();
        Setting::set('rental.kind.vehicles', '1');
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, false);
        $this->get(route('customer.vehicles.home', $this->city->slug))->assertNotFound();
    }

    public function test_owner_number_needs_sign_in_follows_rental_settings_and_the_daily_limit(): void
    {
        $vehicle = $this->liveVehicle();
        $owner = $vehicle->provider->user;
        auth()->logout();
        Livewire::withoutLazyLoading()->test(Contact::class, ['listingId' => $vehicle->id])
            ->assertSee('data-testid="vehicle-sign-in"', false)->assertDontSee($owner->phone);

        Livewire::actingAs($this->customer)->withoutLazyLoading()->test(Contact::class, ['listingId' => $vehicle->id])
            ->call('showPhone')->assertSee($owner->phone)->assertSee('https://wa.me/91'.$owner->phone, false);
        $this->assertDatabaseHas('vehicle_contact_views', ['vehicle_listing_id' => $vehicle->id, 'customer_id' => $this->customer->id]);
        // Seen once: shown straight away next time.
        Livewire::actingAs($this->customer)->withoutLazyLoading()->test(Contact::class, ['listingId' => $vehicle->id])->assertSee($owner->phone);

        Livewire::actingAs($owner)->withoutLazyLoading()->test(Contact::class, ['listingId' => $vehicle->id])->assertSee('This is your vehicle');

        Setting::set('vehicles.phone_views_per_day', '1');
        $second = $this->liveVehicle();
        Livewire::actingAs($this->customer)->withoutLazyLoading()->test(Contact::class, ['listingId' => $second->id])
            ->call('showPhone')->assertSee('seen many numbers today');

        Setting::set('vehicles.show_owner_phone', '0');
        Livewire::actingAs($this->customer)->withoutLazyLoading()->test(Contact::class, ['listingId' => $second->id])
            ->assertDontSee('data-testid="vehicle-show-phone"', false)->assertSee('please call us');
    }

    // ── listing fee (same engine as property) ──

    public function test_with_a_listing_fee_approval_alone_does_not_put_it_live_and_paying_from_the_wallet_does(): void
    {
        Setting::set('vehicles.listing_fee', '199');
        Setting::set('vehicles.featured_fee', '99');
        $vehicle = $this->liveVehicle();
        $owner = $vehicle->provider->user;
        $this->assertTrue(ListingFeeService::required($vehicle));
        $this->assertFalse($vehicle->isLive(), 'Approved, but waits for the fee.');
        $this->assertSame(0, \App\Services\Vehicles\VehicleListingService::publicIn($this->city)->count());

        $page = Livewire::actingAs($owner)->test(VehicleEditor::class, ['listingId' => $vehicle->id])->assertSee('Show your vehicle to customers for 30 days: ₹199.');
        $page->call('availableAgain')->assertSee('Pay the listing fee below');
        $page->call('payFee', 'listing', 'wallet')->assertSee('Your wallet has less than ₹199');

        app(\App\Services\WalletService::class)->credit($owner, 500.0, 'test top-up', 'test:vf:'.Str::random(6));
        Livewire::actingAs($owner)->test(VehicleEditor::class, ['listingId' => $vehicle->id])->call('payFee', 'listing', 'wallet')->assertHasNoErrors();
        $vehicle->refresh();
        $this->assertTrue($vehicle->isLive());
        $this->assertDatabaseHas('listing_fees', ['listing_type' => 'vehicle', 'listing_id' => $vehicle->id, 'kind' => 'listing', 'status' => 'paid', 'amount' => 199]);

        Livewire::actingAs($owner)->test(VehicleEditor::class, ['listingId' => $vehicle->id])->call('payFee', 'featured', 'wallet')->assertHasNoErrors();
        $this->assertTrue($vehicle->fresh()->is_featured);
        $vehicle->update(['featured_until' => now()->subMinute()]);
        $this->artisan('homes:tidy')->assertSuccessful();
        $this->assertFalse($vehicle->fresh()->is_featured, 'homes:tidy ends lapsed features for vehicles too.');
    }

    /** Owner rule 2026-10-26: every module ships with a test login for a real walk-through. */
    public function test_test_accounts_make_a_vehicle_owner_with_one_vehicle_of_every_kind(): void
    {
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $this->franchise->id])->assertSuccessful();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $this->franchise->id])->assertSuccessful();

        $owner = User::where('email', 'test.vehicleowner@1callfix.test')->firstOrFail();
        $vehicles = VehicleListing::whereHas('provider', fn ($q) => $q->where('user_id', $owner->id))->get();
        $this->assertEqualsCanonicalizing(VehicleListingSettings::listIn('vehicles.kinds'), $vehicles->pluck('vehicle_kind')->all(), 'One per kind; running it twice never makes duplicates.');
        foreach ($vehicles as $vehicle) {
            $this->assertTrue($vehicle->isLive());
            $this->assertSame([], VehicleEditor::submitProblems($vehicle), $vehicle->title.' is complete.');
            foreach (['make', 'model', 'year', 'fuel', 'seats', 'driver_options', 'price_day', 'features', 'rule_list', 'about', 'landmark', 'address_line', 'lat', 'lng'] as $field) {
                $this->assertNotEmpty($vehicle->{$field}, "{$vehicle->title}: {$field} is filled");
            }
        }

        // Each one opens on the customer page, and the owner's editor accepts it as it is (every value is on the lists).
        foreach ($vehicles->take(3) as $vehicle) {
            $this->get(route('customer.vehicles.show', [$this->city->slug, $vehicle->slug]))->assertOk()->assertSee($vehicle->title);
            Livewire::actingAs($owner)->test(VehicleEditor::class, ['listingId' => $vehicle->id])->call('save')->assertHasNoErrors();
        }
        auth()->logout();
        $this->actingAs($owner)->get(route('venue.dashboard'))->assertOk()->assertSee('Vehicles for rent');

        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();
        $this->assertSame(0, VehicleListing::whereIn('id', $vehicles->pluck('id'))->where('is_active', true)->count());
        $this->assertSame('suspended', $owner->fresh()->status);
    }
}

<?php

namespace Tests\Feature\Homes;

use App\Actions\RegisterHomeOwnerAction;
use App\Livewire\Customer\Homes\Contact;
use App\Livewire\Customer\Homes\Visits as CustomerVisits;
use App\Livewire\Halls\Settings as RentalSettingsPage;
use App\Livewire\Homes\Manage as HomesManage;
use App\Livewire\Venue\HomeEditor;
use App\Livewire\Venue\Visits as OwnerVisits;
use App\Models\Franchise;
use App\Models\HouseListing;
use App\Models\HouseVisit;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\Homes\HouseListingService;
use App\Services\ModuleActivationService;
use App\Support\HomeSettings;
use App\Support\Modules;
use App\Support\PartnerPage\PartnerPageData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Property for rent (owner 2026-11-14: "monthly house rent is meant ... each and every page with customizable options
 * controlled by superadmin ... multi terrain, multi city, multi climatic conditions"). A property owner joins through
 * /partners, finishes the home in the Rental portal and sends it for review; the team approves it; customers in the
 * city browse with tap filters, ask to visit or see the owner's number; the owner accepts and the customer gets the
 * address. Every list is a Rental Setting that a franchise or zone can change.
 */
class HouseListingTest extends TestCase
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
        $this->customer = User::create(['uuid' => (string) Str::uuid(), 'name' => 'Asha', 'phone' => '9'.fake()->numerify('#########'), 'role' => 'customer', 'status' => 'active']);
    }

    private function draft(): HouseListing
    {
        return app(RegisterHomeOwnerAction::class)->execute(
            zone: $this->zone, existing: null, registration: ['address' => '12-3 Gandhi Road, Nellore', 'lat' => '14.44', 'lng' => '79.98'],
            name: 'Ravi', nationalPhone: '9'.fake()->numerify('#########'), plainPassword: 'secret-pass-1',
        );
    }

    private function fill($page)
    {
        return $page->set('homeType', 'Flat / apartment')->set('size', '2 BHK')->set('furnishing', 'Semi-furnished')
            ->set('tenants', ['Family'])->set('rent', '12000')->set('deposit', '24000')->set('zoneId', (string) $this->zone->id)
            ->set('addressLine', '12-3 Gandhi Road, Nellore')->set('landmark', 'Near RTC bus stand')
            ->set('amenities', ['24-hour water', 'Ceiling fans', 'Lift'])->set('ruleList', ['No smoking']);
    }

    private function photos($page)
    {
        return $page->set('cover', UploadedFile::fake()->image('cover.jpg', 1600, 1067))
            ->set('newPhotos', [UploadedFile::fake()->image('a.jpg', 1600, 1067), UploadedFile::fake()->image('b.jpg', 1600, 1067), UploadedFile::fake()->image('c.jpg', 1600, 1067)]);
    }

    /** A live, approved home in the test city. */
    private function liveHome(array $extra = []): HouseListing
    {
        Storage::fake('public');
        $home = $this->draft();
        $owner = $home->provider->user;
        $this->photos($this->fill(Livewire::actingAs($owner)->test(HomeEditor::class, ['listingId' => $home->id])))->call('submit')->assertHasNoErrors();
        Livewire::actingAs($this->makeSuperAdmin())->test(HomesManage::class)->call('approve', $home->id);
        $home->refresh()->update($extra);

        return $home->fresh();
    }

    // ── settings: every list is Super Admin's, and a city / zone can have its own ──

    public function test_lists_are_settings_and_a_franchise_or_zone_can_have_its_own(): void
    {
        $this->assertContains('2 BHK', HomeSettings::listIn('homes.sizes'));
        $this->assertSame('Weather and climate', HomeSettings::amenityGroups()[2]['title']);
        $this->assertSame([1, 2, 3, 6, 10], HomeSettings::numbersIn('homes.deposit_months'));

        // A hill town (zone) gets its own climate list; the rest of the city keeps the global one.
        Setting::set('homes.amenities', "Weather and climate:\nRoom heater\nFireplace\nTerrain and access:\nSteps to climb", 'zone', $this->zone->id);
        $this->assertSame(['Room heater', 'Fireplace', 'Steps to climb'], HomeSettings::amenityList(['zone_id' => $this->zone->id, 'franchise_id' => $this->franchise->id]));
        $this->assertContains('Air conditioner', HomeSettings::amenityList(['franchise_id' => $this->franchise->id]));
        Setting::set('homes.area_unit', 'sqyd', 'franchise', $this->franchise->id);
        $this->assertSame('sq yd (gaj)', HomeSettings::areaUnit(['franchise_id' => $this->franchise->id]));

        $this->assertNotNull(\App\Support\RentalSettings::validate('homes.deposit_months', "2\nten"));
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(RentalSettingsPage::class)->assertForbidden();
        Livewire::actingAs($this->makeSuperAdmin())->test(RentalSettingsPage::class)->assertOk()
            ->assertSee('Property for rent (monthly)')->assertSee('Facilities an owner can tick')->assertSee('Budget buttons for customers')
            ->set('scopeType', 'franchise')->set('scopeFranchiseId', $this->franchise->id)
            ->assertSee('Start from this list and change it here')
            ->call('useInherited', 'homes__sizes')->assertSet('inputs.homes__sizes', HomeSettings::REGISTRY['homes.sizes']['default']);
    }

    // ── joining and the Rental portal ──

    public function test_partner_card_hands_off_only_when_super_admin_takes_house_owners(): void
    {
        $card = fn () => collect(PartnerPageData::roles())->firstWhere('code', 'homes');
        $this->assertSame('Property owner', $card()['label']);
        $this->assertSame('Rental', $card()['module']);
        Setting::set('homes.recruiting_owners', '0');
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, false);
        $this->assertFalse($card()['hands_off']);
        Setting::set('homes.recruiting_owners', '1');
        $this->assertTrue($card()['hands_off']);
        Setting::set('rental.kind.properties', '0');
        $this->assertNull($card(), 'Property for rent off: no Property owner card.');
    }

    public function test_owner_signs_up_finishes_the_home_and_sends_it_for_review(): void
    {
        Storage::fake('public');
        $home = $this->draft();
        $owner = $home->provider->user;
        $this->assertTrue($owner->holdsPartnerRole('homes'));
        $this->assertFalse($owner->canApplyAsPartner('homes'));
        $this->actingAs($owner)->get(route('venue.dashboard'))->assertOk()->assertSee('Property for rent')->assertSee('Finish your property and send for review');
        $this->actingAs($this->customer)->get(route('venue.dashboard'))->assertForbidden();

        // Missing photos: kept as a draft with the reason.
        $page = $this->fill(Livewire::actingAs($owner)->test(HomeEditor::class, ['listingId' => $home->id]))->call('submit')->assertHasNoErrors(['rent']);
        $this->assertSame('draft', $home->fresh()->status);
        $page->assertSee('Add a cover photo.');

        $this->photos($page)->set('deposit', '')->call('depositMonths', 2)->assertSet('deposit', '24000')->call('submit')->assertHasNoErrors();
        $home->refresh();
        $this->assertSame('in_review', $home->status);
        $this->assertSame('2 BHK flat / apartment for rent in Nellore Zone', $home->title, 'The title is made from the taps.');
        $this->assertSame('2-bhk-flat-apartment-for-rent-in-nellore-zone', $home->slug);
        $this->assertSame(24000.0, (float) $home->deposit);
        $this->assertSame(['Family'], $home->tenants);
    }

    /** Owner 2026-11-14: "property for rent ... shops, offices, buildings also". */
    public function test_a_shop_or_office_needs_no_bhk_size_and_uses_the_business_tenant_list(): void
    {
        Storage::fake('public');
        $home = $this->draft();
        $page = Livewire::actingAs($home->provider->user)->test(HomeEditor::class, ['listingId' => $home->id])
            ->assertSee('For business')->assertSee('Office space');
        $this->photos($page)->set('homeType', 'Shop')->set('furnishing', 'Unfurnished')->set('tenants', ['Family'])
            ->set('rent', '25000')->set('zoneId', (string) $this->zone->id)->set('addressLine', 'Main Bazaar, Nellore')
            ->call('save')->assertHasErrors(['tenants.0']);
        $page->assertSee('Retail shop')->set('tenants', ['Retail shop'])->call('submit')->assertHasNoErrors();

        $home->refresh();
        $this->assertSame('in_review', $home->status);
        $this->assertNull($home->size);
        $this->assertSame('Shop for rent in Nellore Zone', $home->title);

        Livewire::actingAs($this->makeSuperAdmin())->test(HomesManage::class)->call('approve', $home->id);
        $this->get(route('customer.homes.home', [$this->city->slug, 'type' => 'Shop']))->assertOk()->assertSee('Shop for rent in Nellore Zone');
        $this->get(route('customer.homes.home', [$this->city->slug, 'for' => 'Retail shop']))->assertSee('Shop for rent in Nellore Zone');
        $this->assertStringStartsWith(url('/'.$this->city->slug.'/property/'), route('customer.homes.show', [$this->city->slug, $home->slug]));
    }

    public function test_rent_out_of_the_settings_range_and_choices_not_on_the_list_are_refused(): void
    {
        $home = $this->draft();
        $page = $this->fill(Livewire::actingAs($home->provider->user)->test(HomeEditor::class, ['listingId' => $home->id]));
        $page->set('rent', '50')->call('save')->assertHasErrors(['rent']);
        $page->set('rent', '12000')->set('size', 'Castle')->call('save')->assertHasErrors(['size']);
        Setting::set('homes.sizes', "1 BHK\nCastle", 'zone', $this->zone->id);
        $page->call('save')->assertHasNoErrors();
    }

    public function test_admin_approves_and_a_live_home_changes_rent_at_once_but_photos_wait(): void
    {
        $home = $this->liveHome();
        $this->assertSame('approved', $home->status);
        $this->assertTrue($home->isLive());
        $this->assertEqualsWithDelta(now()->addDays(60)->timestamp, $home->expires_at->timestamp, 5, 'Up for Rental Settings\' listing days.');

        $owner = $home->provider->user;
        Livewire::actingAs($owner)->test(HomeEditor::class, ['listingId' => $home->id])
            ->set('rent', '13000')->set('about', 'Brand new paint.')->call('save')->assertHasNoErrors();
        $home->refresh();
        $this->assertSame(13000.0, (float) $home->rent);
        $this->assertNull($home->about);
        $this->assertSame('Brand new paint.', $home->pending_changes['about']);

        Livewire::actingAs($this->makeSuperAdmin())->test(HomesManage::class)->call('approveChanges', $home->id);
        $this->assertSame('Brand new paint.', $home->fresh()->about);
    }

    public function test_rented_and_expired_homes_are_hidden_until_the_owner_says_still_available(): void
    {
        $home = $this->liveHome();
        $url = route('customer.homes.show', [$this->city->slug, $home->slug]);
        $this->get($url)->assertOk();

        Livewire::actingAs($home->provider->user)->test(HomeEditor::class, ['listingId' => $home->id])->call('markRented');
        $this->get($url)->assertNotFound();
        Livewire::actingAs($home->provider->user)->test(HomeEditor::class, ['listingId' => $home->id])->call('stillAvailable');
        $this->get($url)->assertOk();

        $home->update(['expires_at' => now()->subMinute()]);
        $this->get($url)->assertNotFound();
    }

    // ── customer pages ──

    public function test_city_list_filters_by_taps_and_the_home_page_shows_everything_but_the_address(): void
    {
        $home = $this->liveHome();
        $other = $this->liveHome();
        $other->update(['size' => '3 BHK', 'rent' => 30000, 'title' => 'Big house', 'tenants' => ['Bachelor men']]);

        $this->get(route('customer.homes.home', $this->city->slug))->assertOk()
            ->assertSee('Find a property to rent')->assertSee($home->title)->assertSee('Big house')->assertSee('Up to ₹15,000')->assertSee('₹12,000 / month');
        $this->get(route('customer.homes.home', [$this->city->slug, 'budget' => 15000]))->assertSee($home->title)->assertDontSee('Big house');
        $this->get(route('customer.homes.home', [$this->city->slug, 'size' => '3 BHK']))->assertDontSee($home->title)->assertSee('Big house');
        $this->get(route('customer.homes.home', [$this->city->slug, 'for' => 'Family']))->assertSee($home->title)->assertDontSee('Big house');

        $this->get(route('customer.homes.show', [$this->city->slug, $home->slug]))->assertOk()
            ->assertSee('₹12,000 per month')->assertSee('₹24,000')->assertSee('Semi-furnished')->assertSee('Near RTC bus stand')
            ->assertSeeInOrder(['Water', '24-hour water', 'Weather and climate', 'Ceiling fans', 'Terrain and access', 'Lift'])
            ->assertSee('No smoking')->assertSee('data-testid="photo-thumbs"', false)
            ->assertDontSee('12-3 Gandhi Road');

        $this->get(route('customer.rentals.home', $this->city->slug))->assertOk()->assertSee('data-kind="properties"', false)->assertSee($home->title);
    }

    public function test_pages_are_hidden_while_rental_or_houses_for_rent_is_off(): void
    {
        $home = $this->liveHome();
        Setting::set('rental.kind.properties', '0');
        $this->get(route('customer.homes.home', $this->city->slug))->assertNotFound();
        $this->get(route('customer.homes.show', [$this->city->slug, $home->slug]))->assertNotFound();
        Setting::set('rental.kind.properties', '1');
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, false);
        $this->get(route('customer.homes.home', $this->city->slug))->assertNotFound();
    }

    // ── visits and the owner's number ──

    public function test_customer_asks_to_visit_owner_accepts_and_the_address_and_number_are_shared(): void
    {
        $home = $this->liveHome();
        $owner = $home->provider->user;
        $day = now('Asia/Kolkata')->addDays(2)->toDateString();
        $slot = HomeSettings::listIn('homes.visit_slots')[2];

        auth()->logout();
        Livewire::withoutLazyLoading()->test(Contact::class, ['listingId' => $home->id])->assertSee('Sign in to ask for a visit');
        Livewire::actingAs($this->customer)->withoutLazyLoading()->test(Contact::class, ['listingId' => $home->id])
            ->call('startAsking')->set('date', $day)->set('slot', $slot)->set('tenantType', 'Family')->set('message', 'Can I see it Saturday?')
            ->call('ask')->assertSee('Sent. The owner will reply');
        $visit = HouseVisit::firstOrFail();
        $this->assertSame('requested', $visit->status);
        $this->assertSame('Family', $visit->tenant_type);

        // One open request per home.
        $this->expectsNoDuplicate($home, $day, $slot);

        $this->actingAs($this->customer)->get(route('customer.homes.visits'))->assertOk()->assertSee('Waiting for the owner')->assertDontSee('12-3 Gandhi Road');
        Livewire::actingAs($owner)->test(OwnerVisits::class)->assertSee('Asha')->assertDontSee($this->customer->phone)
            ->call('answer', $visit->id)->set('note', 'Come at 5 PM')->call('accept', $visit->id);
        $this->assertSame('accepted', $visit->fresh()->status);

        $this->actingAs($this->customer)->get(route('customer.homes.visits'))->assertSee('Visit accepted')->assertSee('12-3 Gandhi Road')->assertSee('Come at 5 PM')->assertSee($owner->phone);
        Livewire::actingAs($owner)->test(OwnerVisits::class)->set('tab', 'accepted')->assertSee($visit->customer_phone);

        // Another owner never sees it.
        $stranger = $this->draft()->provider->user;
        Livewire::actingAs($stranger)->test(OwnerVisits::class)->assertDontSee('Asha');
        Livewire::actingAs($stranger)->test(OwnerVisits::class)->call('accept', $visit->id)->assertNotFound();
    }

    private function expectsNoDuplicate(HouseListing $home, string $day, string $slot): void
    {
        try {
            app(HouseListingService::class)->requestVisit($this->customer, $home, $day, $slot, 'Asha', '9876543210');
            $this->fail('A second open request for the same home must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already asked', $e->getMessage());
        }
    }

    public function test_visit_rules_day_window_time_daily_limit_own_home_decline_cancel_and_tidy(): void
    {
        $home = $this->liveHome();
        $homes = app(HouseListingService::class);
        $slot = HomeSettings::listIn('homes.visit_slots')[0];
        $day = now('Asia/Kolkata')->addDay()->toDateString();

        foreach ([[now()->addDays(40)->toDateString(), $slot, 'Choose a day'], [$day, 'Midnight', 'Choose a visit time']] as [$d, $s, $why]) {
            try {
                $homes->requestVisit($this->customer, $home, $d, $s, 'Asha', '9876543210');
                $this->fail($why);
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }
        try {
            $homes->requestVisit($home->provider->user, $home, $day, $slot, 'Ravi', '9876543210');
            $this->fail('Own home');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('your own property', $e->getMessage());
        }

        $visit = $homes->requestVisit($this->customer, $home, $day, $slot, 'Asha', '9876543210');
        try {
            $homes->decline($visit, $home->provider->user, '');
            $this->fail('A reason is needed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('why', $e->getMessage());
        }
        $homes->decline($visit, $home->provider->user, 'Already rented');
        $this->assertSame('declined', $visit->fresh()->status);

        $again = $homes->requestVisit($this->customer, $home, $day, $slot, 'Asha', '9876543210');
        Livewire::actingAs($this->customer)->test(CustomerVisits::class)->call('cancel', $again->id);
        $this->assertSame('cancelled', $again->fresh()->status);

        Setting::set('homes.visits_per_day', '2');
        $third = $this->liveHome();
        try {
            $homes->requestVisit($this->customer, $third, $day, $slot, 'Asha', '9876543210');
            $this->fail('Daily limit');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('many visits today', $e->getMessage());
        }

        $old = HouseVisit::create(['code' => 'HV-OLD1', 'house_listing_id' => $home->id, 'customer_id' => $this->customer->id, 'visit_date' => now()->subDays(3)->toDateString(),
            'slot' => $slot, 'customer_name' => 'Asha', 'customer_phone' => '9876543210', 'status' => 'requested']);
        $this->artisan('homes:tidy')->assertSuccessful();
        $this->assertSame('expired', $old->fresh()->status);
    }

    public function test_owner_number_follows_rental_settings_and_the_daily_limit(): void
    {
        $home = $this->liveHome();
        $owner = $home->provider->user;
        Livewire::actingAs($this->customer)->withoutLazyLoading()->test(Contact::class, ['listingId' => $home->id])
            ->call('showPhone')->assertSee($owner->phone);
        $this->assertDatabaseHas('house_contact_views', ['house_listing_id' => $home->id, 'customer_id' => $this->customer->id]);

        Setting::set('homes.phone_views_per_day', '1');
        $second = $this->liveHome();
        Livewire::actingAs($this->customer)->withoutLazyLoading()->test(Contact::class, ['listingId' => $second->id])
            ->call('showPhone')->assertSee('seen many numbers today');
        Livewire::actingAs($this->customer)->withoutLazyLoading()->test(Contact::class, ['listingId' => $home->id])
            ->call('showPhone')->assertSee($owner->phone); // already seen: still shown

        Setting::set('homes.show_owner_phone', '0');
        Livewire::actingAs($this->customer)->withoutLazyLoading()->test(Contact::class, ['listingId' => $second->id])
            ->assertDontSee('data-testid="home-show-phone"', false);
    }

    // ── admin, old nightly stays, test login ──

    public function test_admin_needs_the_permission_sends_back_with_a_reason_and_sees_visits(): void
    {
        $home = $this->liveHome();
        app(HouseListingService::class)->requestVisit($this->customer, $home, now('Asia/Kolkata')->addDay()->toDateString(), HomeSettings::listIn('homes.visit_slots')[0], 'Asha', '9876543210');
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(HomesManage::class)->assertForbidden();

        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(HomesManage::class)->set('statusFilter', 'visits')->assertSee('Asha');
        Livewire::actingAs($admin)->test(HomesManage::class)->call('sendBack', $home->id)->assertHasErrors(['reason'])
            ->set('reason', 'Photos are too dark')->call('sendBack', $home->id);
        $this->assertSame('rejected', $home->fresh()->status);
        $this->actingAs($home->provider->user)->get(route('venue.homes.edit', $home->id))->assertSee('Photos are too dark');
    }

    public function test_old_nightly_property_booking_is_retired(): void
    {
        $this->assertFalse(collect(\App\Support\AdminNav::forUser($this->makeSuperAdmin())[0]['rental']['items'])->contains('route', 'admin.properties.index'));
        \App\Models\Module::where('code', Modules::RENTAL)->update(['is_implemented' => true]);
        $this->expectException(\App\Exceptions\ModuleNotActiveException::class);
        app(\App\Actions\CreatePropertyReservationAction::class)->execute(['franchise_id' => $this->franchise->id, 'property_id' => 0]);
    }

    /** Owner rule 2026-10-26: every module ships with a test login for a real walk-through. */
    public function test_test_accounts_make_a_property_owner_with_four_homes_and_a_shop(): void
    {
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $this->franchise->id])->assertSuccessful();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $this->franchise->id])->assertSuccessful();

        $owner = User::where('email', 'test.homeowner@1callfix.test')->firstOrFail();
        $homes = HouseListing::whereHas('provider', fn ($q) => $q->where('user_id', $owner->id))->get();
        // Owner 2026-11-14: "a test property in each category with all the options filled".
        $this->assertCount(count(HomeSettings::allTypes()), $homes, 'One per kind; running it twice never makes duplicates.');
        $this->assertEqualsCanonicalizing(HomeSettings::allTypes(), $homes->pluck('home_type')->all(), 'Every kind, to live in and for business, has one.');
        foreach ($homes as $home) {
            $this->assertTrue($home->isLive());
            $this->assertSame([], HomeEditor::submitProblems($home), $home->title.' is complete.');
            foreach (['area', 'floor', 'facing', 'furnishing', 'tenants', 'rent', 'deposit', 'available_from', 'landmark', 'address_line', 'lat', 'lng', 'amenities', 'rule_list', 'about'] as $field) {
                $this->assertNotEmpty($home->{$field}, "{$home->title}: {$field} is filled");
            }
            $this->assertCount(3, $home->photos);
            $this->assertSame(HomeSettings::isCommercial($home->home_type), $home->size === null, $home->title.': BHK size only for homes to live in');
        }
        $this->assertNotNull($homes->firstWhere('home_type', 'Flat / apartment')->maintenance);

        // Each one opens on the customer page, and the owner's editor accepts it as it is (every value is on the lists).
        foreach ($homes->take(3)->concat($homes->filter(fn ($h) => $h->size === null)->take(2)) as $home) {
            $this->get(route('customer.homes.show', [$this->city->slug, $home->slug]))->assertOk()->assertSee($home->title);
            Livewire::actingAs($owner)->test(HomeEditor::class, ['listingId' => $home->id])->call('save')->assertHasNoErrors();
        }
        auth()->logout();
        $this->actingAs($owner)->get(route('venue.dashboard'))->assertOk()->assertSee('Property for rent');

        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();
        $this->assertSame(0, HouseListing::whereIn('id', $homes->pluck('id'))->where('is_active', true)->count());
        $this->assertSame('suspended', $owner->fresh()->status);
    }
}

<?php

namespace Tests\Feature\RentalItems;

use App\Livewire\Customer\RentItems\Contact;
use App\Livewire\RentalItems\Manage;
use App\Models\Franchise;
use App\Models\RentalItem;
use App\Models\Setting;
use App\Models\User;
use App\Models\Zone;
use App\Services\ModuleActivationService;
use App\Services\RentalItems\RentalItemService;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Rent anything (owner decision 2026-11-18), Stage 3: /{city}/rent with tap filters, the item page (photos, ♡ Like,
 * Share, never the owner's number in the page), the contact panel (owner's number, WhatsApp, report), the Rentals tile,
 * and the team queue in Admin, where each franchise sees only its own zone's items.
 */
class RentItemPagesTest extends TestCase
{
    use RefreshDatabase;
    use LiveCity;
    use RbacTestHelpers;

    private $city;
    private Franchise $franchise;
    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->city = $this->liveCity('Nellore');
        $this->franchise = Franchise::where('city_id', $this->city->id)->firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);
        Setting::set('rental.kind.items', '1');
    }

    private function person(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active', 'phone_verified_at' => now(),
        ]);
    }

    private function item(array $extra = [], string $status = 'approved'): RentalItem
    {
        $name = $extra['name'] ?? 'JBL party speaker';
        $title = RentalItem::makeTitle($name, $this->zone->name);

        return RentalItem::create($extra + [
            'user_id' => $this->person()->id, 'franchise_id' => $this->franchise->id, 'zone_id' => $this->zone->id,
            'name' => $name, 'title' => $title, 'slug' => RentalItem::uniqueSlug($title), 'category' => 'Sound and lights',
            'condition' => 'Good', 'price' => 600, 'price_unit' => 'Day', 'deposit' => 2000, 'about' => 'Comes with a mic.',
            'landmark' => 'Near RTC bus stand', 'cover_image' => 'https://example.test/cover.jpg', 'photos' => ['https://example.test/a.jpg'],
            'status' => $status, 'is_active' => $status === 'approved', 'approved_at' => now(), 'submitted_at' => now()->subHours(3),
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function test_city_page_lists_live_items_with_tap_filters_and_hides_the_rest(): void
    {
        $speaker = $this->item(['can_deliver' => true]);
        $table = $this->item(['name' => 'Dining table', 'category' => 'Furniture', 'price' => 300]);
        $this->item(['name' => 'Draft camera'], 'draft');
        $this->item(['name' => 'Waiting tent'], 'in_review');

        $this->get(route('customer.rent.home', $this->city->slug))->assertOk()
            ->assertSee('Rent anything near you')->assertSee('JBL party speaker')->assertSee('Dining table')
            ->assertSee('₹600 / day')->assertDontSee('Draft camera')->assertDontSee('Waiting tent')
            ->assertSee('Rent out your things')->assertSee('Save '.$speaker->title);
        $this->get(route('customer.rent.home', [$this->city->slug, 'kind' => 'Furniture']))->assertSee('Dining table')->assertDontSee('JBL party speaker');
        $this->get(route('customer.rent.home', [$this->city->slug, 'deliver' => '1']))->assertSee('JBL party speaker')->assertDontSee('Dining table');
        $this->get(route('customer.rent.home', [$this->city->slug, 'sort' => 'cheap']))->assertSeeInOrder(['Dining table', 'JBL party speaker']);

        $this->get(route('customer.rentals.home', $this->city->slug))->assertOk()->assertSee('data-kind="items"', false)->assertSee('Rent anything');
        $this->assertSame(2, \App\Livewire\Customer\Rentals\Home::liveCountIn($this->city) - \App\Services\Homes\HouseListingService::publicIn($this->city)->count() - \App\Livewire\Customer\Halls\Home::publicHallsIn($this->city)->count());
        $this->assertNotNull($table);
    }

    /** Owner rule 2026-10-26: every module ships with a test login for a real walk-through. */
    public function test_test_accounts_make_a_renter_with_live_items_and_disable_switches_them_off(): void
    {
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $this->franchise->id])->assertSuccessful();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $this->franchise->id])->assertSuccessful();

        $renter = User::where('email', 'test.renter@1callfix.test')->firstOrFail();
        $this->assertSame('customer', $renter->role);
        $this->assertNotNull($renter->phone_verified_at, 'Posting needs a verified mobile.');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('test-pass-123', $renter->password), 'Signs in with the one test password.');

        $items = RentalItem::where('user_id', $renter->id)->get();
        $this->assertCount(2, $items, 'Running it twice never makes duplicates, and leaves one free slot to post.');
        foreach ($items as $item) {
            $this->assertTrue($item->isLive(), $item->title.' is up.');
            $this->assertSame($this->zone->id, $item->zone_id);
            $this->get(route('customer.rent.show', [$this->city->slug, $item->slug]))->assertOk()->assertSee($item->title);
            // The owner's editor accepts it as it is (every value is on the lists).
            Livewire::actingAs($renter)->test(\App\Livewire\Customer\Account\RentItemEditor::class, ['item' => $item->id])->call('save')->assertHasNoErrors();
        }
        $this->get(route('customer.rent.home', $this->city->slug))->assertSee('Test Sony camera')->assertSee('Test party lights set');

        // test.customer (someone else) sees the renter's number from the contact panel.
        $customer = User::where('email', 'test.customer@1callfix.test')->firstOrFail();
        Livewire::actingAs($customer)->test(Contact::class, ['itemId' => $items->first()->id])->call('showPhone')->assertSee($renter->phone);

        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();
        $this->assertSame(0, RentalItem::where('user_id', $renter->id)->where('is_active', true)->count());
        $this->assertSame('suspended', $renter->fresh()->status);
        $this->get(route('customer.rent.home', $this->city->slug))->assertDontSee('Test Sony camera');
    }

    /** Walk-through 2026-11-19: an empty city page offers the post button; only Super Admin sees the settings link. */
    public function test_empty_page_offers_posting_and_settings_link_is_for_super_admin_only(): void
    {
        $this->get(route('customer.rent.home', $this->city->slug))->assertOk()->assertSee('data-testid="rent-post-empty"', false);
        $this->item();
        $this->get(route('customer.rent.home', $this->city->slug))->assertDontSee('data-testid="rent-post-empty"', false);

        $team = $this->makeUserWithPermission('rental_items.manage', 'franchise', $this->franchise->id);
        Livewire::actingAs($team)->test(Manage::class)->assertDontSee('Rental Settings →');
        Livewire::actingAs($this->makeSuperAdmin())->test(Manage::class)->assertSee('Rental Settings →');
    }

    public function test_pages_close_where_rent_anything_is_off(): void
    {
        $item = $this->item();
        Setting::set('rental.kind.items', '0');
        $this->get(route('customer.rent.home', $this->city->slug))->assertNotFound();
        $this->get(route('customer.rent.show', [$this->city->slug, $item->slug]))->assertNotFound();
    }

    public function test_item_page_shows_the_item_but_never_the_owners_number(): void
    {
        $item = $this->item(['can_deliver' => true]);
        $this->get(route('customer.rent.show', [$this->city->slug, $item->slug]))->assertOk()
            ->assertSee('JBL party speaker for rent in Nellore Zone')->assertSee('₹2,000')->assertSee('Owner can deliver')
            ->assertSee('Near RTC bus stand')->assertSee('Comes with a mic.')->assertSee('data-testid="photo-thumbs"', false)
            ->assertSee('Share')->assertDontSee($item->user->phone);
        $this->assertStringStartsWith(url('/'.$this->city->slug.'/rent/'), route('customer.rent.show', [$this->city->slug, $item->slug]));
    }

    public function test_contact_panel_shows_the_number_to_signed_in_people_and_takes_reports(): void
    {
        $item = $this->item();
        $owner = $item->user;
        auth()->logout();
        Livewire::withoutLazyLoading()->test(Contact::class, ['itemId' => $item->id])
            ->assertSee('data-testid="rent-sign-in"', false)->assertDontSee($owner->phone);

        $customer = $this->person();
        Livewire::actingAs($customer)->withoutLazyLoading()->test(Contact::class, ['itemId' => $item->id])
            ->call('showPhone')->assertSee($owner->phone)->assertSee('https://wa.me/91'.$owner->phone, false);
        Livewire::actingAs($owner)->withoutLazyLoading()->test(Contact::class, ['itemId' => $item->id])
            ->assertSee('This is your item')->assertDontSee('data-testid="rent-report-open"', false);

        Livewire::actingAs($customer)->withoutLazyLoading()->test(Contact::class, ['itemId' => $item->id])
            ->call('sendReport')->assertHasErrors('reason')
            ->set('reason', 'scam')->set('note', 'Photos copied from the internet')->call('sendReport')
            ->assertSee('Our team will check this item');
        $this->assertDatabaseHas('rental_item_reports', ['rental_item_id' => $item->id, 'user_id' => $customer->id, 'reason' => 'scam']);

        foreach ([$this->person(), $this->person()] as $more) {
            Livewire::actingAs($more)->withoutLazyLoading()->test(Contact::class, ['itemId' => $item->id])->set('reason', 'not_allowed')->call('sendReport');
        }
        $this->assertNotNull($item->fresh()->hidden_for_reports_at);
        $this->get(route('customer.rent.show', [$this->city->slug, $item->slug]))->assertNotFound();
    }

    public function test_the_team_queue_needs_the_permission_and_approves_or_sends_back(): void
    {
        $this->actingAs($this->person())->get(route('admin.rental-items.index'))->assertForbidden();

        $waiting = $this->item(['name' => 'Tent'], 'in_review');
        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(Manage::class)->assertSee('Tent')->assertSee('Waiting for review (1)')
            ->call('approve', $waiting->id)->assertSee('Approved. Customers see it now.');
        $this->assertTrue(RentalItemService::visible($waiting->fresh()));

        $other = $this->item(['name' => 'Drill'], 'in_review');
        Livewire::actingAs($admin)->test(Manage::class)->call('sendBack', $other->id)->assertHasErrors('reason')
            ->set('reason', 'Please add a real photo.')->call('sendBack', $other->id);
        $this->assertSame('rejected', $other->fresh()->status);
        $this->assertSame('Please add a real photo.', $other->fresh()->rejection_reason);
    }

    public function test_reported_items_are_put_back_or_taken_down_by_the_team(): void
    {
        $item = $this->item();
        foreach (range(1, 3) as $n) {
            app(RentalItemService::class)->report($this->person(), $item->fresh(), 'scam');
        }
        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(Manage::class)->set('statusFilter', 'reported')->assertSee('Reported (1)')->assertSee('3 report(s), hidden')
            ->call('view', $item->id)->assertSee('Looks fake or a scam')->call('dismissReports', $item->id)->assertSee('The item is up again');
        $this->assertTrue(RentalItemService::visible($item->fresh()));

        app(RentalItemService::class)->report($this->person(), $item->fresh(), 'not_allowed');
        Livewire::actingAs($admin)->test(Manage::class)->set('reason', 'Not allowed on 1CallFix.')->call('upholdReports', $item->id);
        $this->assertSame('rejected', $item->fresh()->status);
    }

    /** The owner's model: Alif Traders' team (Nellore West) sees only Alif's items, never Azam's. */
    public function test_a_franchise_team_sees_only_its_own_zones_items(): void
    {
        $alif = $this->extraFranchise($this->city, 'Alif Traders');
        $west = $this->extraZone($alif, 'Nellore West');
        $azamItem = $this->item(['name' => 'Azam camera'], 'in_review');
        $alifItem = $this->item(['name' => 'Alif tent', 'franchise_id' => $alif->id, 'zone_id' => $west->id], 'in_review');

        $alifTeam = $this->makeUserWithPermission('rental_items.manage', 'franchise', $alif->id);
        Livewire::actingAs($alifTeam)->test(Manage::class)->assertSee('Alif tent')->assertDontSee('Azam camera')
            ->call('approve', $azamItem->id)->assertStatus(404);
        $this->assertSame('in_review', $azamItem->fresh()->status);
        Livewire::actingAs($alifTeam)->test(Manage::class)->call('approve', $alifItem->id);
        $this->assertSame('approved', $alifItem->fresh()->status);
    }
}

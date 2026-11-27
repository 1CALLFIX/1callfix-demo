<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\HomeSections\Manage;
use App\Models\ActivityLog;
use App\Models\Franchise;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Support\HomeDoors;
use App\Support\SiteText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/** Owner mockup 2026-10-30: "Explore 1CallFix" doors, one per live business, all controlled by the Super Admin. */
class HomeDoorsTest extends TestCase
{
    use BookingFixtureHelpers;
    use LiveCity;
    use MarketplaceFixtureHelpers;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private Franchise $franchise;
    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
        $this->makeCategoryAndService();
        $this->franchise = Franchise::firstOrFail();
        $this->zone = Zone::where('franchise_id', $this->franchise->id)->firstOrFail();
        app(CustomerLocationContext::class)->setZone($this->zone->id);
    }

    private function doorsOrder(string $html): array
    {
        preg_match_all('/data-door="([a-z]+)"/', $html, $m);

        return $m[1];
    }

    public function test_only_services_shows_while_parcel_and_shop_are_off(): void
    {
        $html = $this->get('/')->assertOk()->assertSee('data-testid="home-doors"', false)
            ->assertSee('Explore 1CallFix')->assertSee('1 service to book')->getContent();

        $this->assertSame(['services'], $this->doorsOrder($html));
    }

    public function test_parcel_and_shop_doors_appear_where_they_are_on_with_real_live_numbers(): void
    {
        $this->activateParcelFor($this->franchise);
        $this->activateMarketplaceModuleFor($this->franchise);
        $this->makeStore($this->franchise, $this->zone, null, ['name' => 'Open Shop', 'is_open' => true]);
        $this->makeStore($this->franchise, $this->zone, null, ['name' => 'Shut Shop', 'is_open' => false]);

        $html = $this->get('/')->assertOk()->assertSee('1 shop open now')->assertSee('From ₹')->getContent();
        $this->assertSame(['services', 'parcel', 'shop'], $this->doorsOrder($html));

        Setting::set(HomeDoors::LIVE_INFO, '0');
        $this->get('/')->assertOk()->assertSee('Local shops, fast delivery')->assertDontSee('1 shop open now');
    }

    /**
     * Owner 2026-11-23: the live line leaves the screen (kept for screen readers); the number sits as a badge on the
     * picture, only from the Super Admin's "from" number up (default 10, so "1 shop" never shows at launch).
     */
    public function test_the_live_number_is_a_badge_on_the_picture_from_the_admins_number_up(): void
    {
        $badges = function (): array {
            preg_match_all('/data-door-badge="([a-z]+)">(\d+)</', $this->get('/')->assertOk()->getContent(), $m);

            return array_combine($m[1], $m[2]);
        };

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="sr-only">1 service to book</span>', $html, 'read out, not shown');
        $this->assertStringContainsString('class="cf-explore-title', $html, 'heading kept for screen readers, hidden on phones');
        $this->assertSame(10, HomeDoors::badgeFrom());
        $this->assertSame([], $badges(), '1 is below 10: no badge');

        $this->actingAs($this->makeSuperAdmin());
        Livewire::test(Manage::class)->assertSet('doorBadgeFrom', '10')
            ->set('doorBadgeFrom', '-1')->call('save')->assertHasErrors('doorBadgeFrom')
            ->set('doorBadgeFrom', '1')->call('save')->assertHasNoErrors();
        auth()->logout();
        $this->assertSame(['services' => '1'], $badges());

        Setting::set(HomeDoors::LIVE_INFO, '0');
        $this->assertSame([], $badges(), 'live numbers off: no badge');
        Setting::set(HomeDoors::LIVE_INFO, '1');
        Setting::set(HomeDoors::BADGE_FROM, '0');
        $this->assertSame([], $badges(), '0 = never');
    }

    public function test_the_super_admin_orders_hides_moves_and_pictures_the_doors(): void
    {
        Storage::fake('public');
        $this->activateParcelFor($this->franchise);
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(Manage::class)
            ->set('doorOrder.parcel', '1')->set('doorOrder.services', '2')
            ->set('showPromise', false)
            ->set('doorImage.parcel', UploadedFile::fake()->image('door.png', 200, 200))
            ->call('save')->assertSee('Saved. The homepage shows it now.');

        $this->assertTrue(ActivityLog::where('description', 'Setting home.door.parcel.order changed')->exists());
        $path = Setting::get(HomeDoors::imageKey('parcel'));
        Storage::disk('public')->assertExists($path);

        auth()->logout();
        $html = $this->get('/')->assertOk()->assertDontSee(SiteText::get('home.promise'))->assertSee($path)->getContent();
        $this->assertSame(['parcel', 'services'], $this->doorsOrder($html));

        Setting::set('home.door.parcel.show', '0');
        $this->assertSame(['services'], $this->doorsOrder($this->get('/')->getContent()));
        Setting::set(HomeDoors::SHOW, '0');
        $this->get('/')->assertOk()->assertDontSee('data-testid="home-doors"', false);
    }

    public function test_the_doors_read_in_telugu_and_only_a_super_admin_edits_them(): void
    {
        Setting::set(SiteText::TELUGU_ENABLED, '1');
        $this->withCookie(SiteText::COOKIE, 'te')->get('/')->assertOk()->assertSee('1CallFix లో చూడండి')->assertSee('సేవలు');

        $this->actingAs($this->makeUserWithNoPermissions())->get(route('admin.home-sections'))->assertForbidden();
        $this->actingAs($this->makeSuperAdmin())->get(route('admin.home-sections'))->assertOk()->assertSee('Homepage sections');
    }

    public function test_the_super_admin_renames_a_door_in_english_and_telugu(): void
    {
        $this->actingAs($this->makeSuperAdmin());
        Livewire::test(Manage::class)
            ->assertSet('doorWords.services.title_en', 'Services')
            ->set('doorWords.services.title_en', 'Home services')->set('doorWords.services.line_en', 'Fixed by trusted pros')
            ->set('doorWords.services.title_te', 'ఇంటి సేవలు')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Home services', SiteText::get('home.door.services.title'));
        $this->assertSame('Fixed by trusted pros', SiteText::get('home.door.services.line'));
        $this->assertSame('ఇంటి సేవలు', SiteText::saved('home.door.services.title', 'te'));
        auth()->logout();
        $this->get('/')->assertOk()->assertSee('Home services');

        // Back to the built-in words: blank saves nothing.
        $this->actingAs($this->makeSuperAdmin());
        Livewire::test(Manage::class)->set('doorWords.services.title_en', '')->call('save');
        $this->assertSame('Services', SiteText::get('home.door.services.title'));
    }
    /**
     * 1CallMove (owner 2026-11-27): the Parcel door says "Transport" with the box + truck picture only once a 3-wheeler or
     * truck can be booked in the area, in the sticky bar too; an uploaded picture still wins.
     */
    public function test_the_parcel_door_becomes_transport_once_a_truck_can_be_booked(): void
    {
        $this->activateParcelFor($this->franchise);
        $this->get("/")->assertOk()->assertSee(">Parcel<", false)->assertDontSee("Transport")->assertDontSee("door-move.svg");

        Setting::set(\App\Services\Parcel\ParcelSettings::vehicleKey("mini_truck", "base_fare"), "300");
        $this->get("/")->assertOk()->assertDontSee("Transport", false); // half a fare card is not enough

        Setting::set(\App\Services\Parcel\ParcelSettings::vehicleKey("mini_truck", "per_km_rate"), "25");
        $html = $this->get("/")->assertOk()->assertSee("door-move.svg")->getContent();
        $this->assertSame(2, substr_count($html, ">Transport<"), "the door and the sticky bar");
        $this->assertSame(["services", "parcel"], $this->doorsOrder($html));

        Storage::fake("public");
        Setting::set(HomeDoors::imageKey("parcel"), "home/doors/own.png");
        $this->get("/")->assertOk()->assertSee("home/doors/own.png")->assertDontSee("door-move.svg");
    }
}

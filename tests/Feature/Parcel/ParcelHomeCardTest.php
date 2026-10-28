<?php

namespace Tests\Feature\Parcel;

use App\Livewire\SiteControls\Manage;
use App\Models\ActivityLog;
use App\Models\Franchise;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Customer\CustomerLocationContext;
use App\Support\SiteControls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\Feature\Support\ParcelOrderFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-10-26: the homepage parcel card (position, colour, title, picture, "from ₹…" from Parcel Settings), a
 * Parcel icon as the first category icon, and the same picture on the /parcel page. All Super Admin settings; nothing
 * shows where Parcel is switched off.
 */
class ParcelHomeCardTest extends TestCase
{
    use BookingFixtureHelpers;
    use LiveCity;
    use ParcelOrderFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private Franchise $franchise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
        $this->makeCategoryAndService();
        $this->franchise = Franchise::firstOrFail();
        app(CustomerLocationContext::class)->setZone(Zone::where('franchise_id', $this->franchise->id)->firstOrFail()->id);
    }

    public function test_nothing_parcel_shows_where_parcel_is_off(): void
    {
        $this->configureParcelPricing();

        $this->get('/')->assertOk()
            ->assertDontSee('id="parcel-heading"', false)
            ->assertDontSee(SiteControls::DEFAULT_PARCEL_ICON);
    }

    public function test_default_card_icon_and_price_where_parcel_is_on(): void
    {
        $this->activateParcelFor($this->franchise);

        $this->get('/')->assertOk()
            ->assertSee('id="parcel-heading"', false)
            ->assertSee(SiteControls::DEFAULT_PARCEL_TITLE)
            ->assertSee(SiteControls::DEFAULT_PARCEL_SUBTITLE)
            ->assertSee('from ₹40')
            ->assertSee(SiteControls::DEFAULT_PARCEL_IMAGE)
            ->assertSee(SiteControls::DEFAULT_PARCEL_ICON)
            ->assertSee('NEW')
            ->assertSee(route('customer.parcels.index'), false)
            // The Parcel icon is the first tile of the category grid; the card sits after the banner by default.
            ->assertSeeInOrder(['cf-cat-grid', SiteControls::DEFAULT_PARCEL_ICON, 'id="parcel-heading"'], false);
    }

    public function test_the_card_moves_with_the_position_setting(): void
    {
        $this->activateParcelFor($this->franchise);

        Setting::set(SiteControls::PARCEL_POSITION, 'top');
        $this->get('/')->assertSeeInOrder(['id="parcel-heading"', 'cf-cat-grid'], false);

        Setting::set(SiteControls::PARCEL_POSITION, 'after_categories');
        $this->get('/')->assertSeeInOrder(['cf-cat-grid', 'id="parcel-heading"', 'id="new-heading"'], false);
    }

    public function test_switches_hide_the_icon_badge_and_price(): void
    {
        $this->activateParcelFor($this->franchise);
        Setting::set(SiteControls::PARCEL_GRID_TILE, '0');
        Setting::set(SiteControls::PARCEL_SHOW_PRICE, '0');
        Setting::set(SiteControls::PARCEL_TITLE, 'Courier');
        Setting::set(SiteControls::PARCEL_COLOUR, 'slate');

        $this->get('/')->assertOk()
            ->assertSee('id="parcel-heading"', false)
            ->assertSee('Courier')
            ->assertSee('from-slate-800', false)
            ->assertDontSee(SiteControls::DEFAULT_PARCEL_ICON)
            ->assertDontSee('from ₹40');
    }

    public function test_chips_buttons_label_and_parcel_page_text_are_settings(): void
    {
        $this->activateParcelFor($this->franchise);
        Setting::set(SiteControls::PARCEL_CHIPS, 'Medicines, Gifts');
        Setting::set(SiteControls::PARCEL_SEND_LABEL, 'Book a rider');
        Setting::set(SiteControls::PARCEL_TRACK_LABEL, 'My parcels');
        Setting::set(SiteControls::PARCEL_BADGE_TEXT, 'FAST');
        Setting::set(SiteControls::PARCEL_PAGE_HEADING, 'Courier anything');
        Setting::set(SiteControls::PARCEL_PAGE_INTRO, 'Picked up in minutes.');

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['Medicines', 'Gifts'])
            ->assertDontSee('Lunch box')
            ->assertSee('Book a rider')->assertSee('My parcels')->assertSee('FAST')
            ->assertDontSee(SiteControls::DEFAULT_PARCEL_SEND_LABEL);

        $this->actingAs($this->makeCustomer())->get('/parcel')->assertOk()
            ->assertSee('Courier anything')->assertSee('Picked up in minutes.');

        Setting::set(SiteControls::PARCEL_SHOW_CHIPS, '0');
        $this->get('/')->assertOk()->assertDontSee('aria-label="What you can send"', false);
    }

    public function test_no_price_badge_when_the_base_fare_is_unset(): void
    {
        $this->activateParcelFor($this->franchise);
        Setting::clear('parcel.base_fare', 'global', null);

        $this->get('/')->assertOk()->assertSee('id="parcel-heading"', false)->assertDontSee('from ₹');
    }

    public function test_super_admin_saves_uploads_and_resets_the_pictures_with_an_audit_trail(): void
    {
        Storage::fake('public');
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('parcelPosition', 'sideways')->call('save')->assertHasErrors('parcelPosition');

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('parcelImageFile', UploadedFile::fake()->create('evil.svg', 4, 'image/svg+xml'))
            ->call('save')->assertHasErrors('parcelImageFile');

        Livewire::actingAs($admin)->test(Manage::class)
            ->set('parcelPosition', 'top')
            ->set('parcelTitle', 'Courier')
            ->set('parcelColour', 'amber')
            ->set('parcelGridTile', false)
            ->set('parcelSendLabel', 'Book a rider')
            ->set('parcelImageFile', UploadedFile::fake()->image('card.png', 800, 550))
            ->set('parcelIconFile', UploadedFile::fake()->image('icon.png', 400, 400))
            ->call('save')->assertHasNoErrors();

        $this->assertSame('top', Setting::get(SiteControls::PARCEL_POSITION));
        $this->assertSame('Courier', Setting::get(SiteControls::PARCEL_TITLE));
        $this->assertSame('amber', Setting::get(SiteControls::PARCEL_COLOUR));
        $this->assertSame('0', Setting::get(SiteControls::PARCEL_GRID_TILE));
        $this->assertSame('Book a rider', Setting::get(SiteControls::PARCEL_SEND_LABEL));
        $image = Setting::get(SiteControls::PARCEL_IMAGE);
        $this->assertStringStartsWith('site/parcel/', $image);
        Storage::disk('public')->assertExists($image);
        Storage::disk('public')->assertExists(Setting::get(SiteControls::PARCEL_ICON));
        $this->assertTrue(ActivityLog::where('description', 'Setting '.SiteControls::PARCEL_IMAGE.' changed')->exists());

        Livewire::actingAs($admin)->test(Manage::class)->call('resetParcelPicture', 'image');
        $this->assertNull(Setting::get(SiteControls::PARCEL_IMAGE));
        Storage::disk('public')->assertMissing($image);
    }

    public function test_only_a_super_admin_can_reset_a_picture(): void
    {
        $editor = $this->makeUserWithPermission('settings.manage', 'global');

        Livewire::actingAs($editor)->test(Manage::class)->assertForbidden();
    }

    public function test_the_parcel_page_shows_the_same_picture(): void
    {
        $this->activateParcelFor($this->franchise);

        $this->actingAs($this->makeCustomer())->get('/parcel')->assertOk()
            ->assertSee('Send a parcel')
            ->assertSee(SiteControls::DEFAULT_PARCEL_IMAGE)
            ->assertSee(SiteControls::DEFAULT_PARCEL_SUBTITLE);
    }
}

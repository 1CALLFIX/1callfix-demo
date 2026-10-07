<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\Country;
use App\Models\Module;
use App\Models\PartnerBenefit;
use App\Models\Setting;
use App\Services\ModuleActivationService;
use App\Support\PartnerPage\PartnerPageData;
use App\Support\PartnerPage\PartnerPageSettings as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public partner page (REF 1CF-PARTNER-PAGE-001, B2): role cards from the module registry, every word and list
 * from the settings store, unconfirmed claims off by default, empty blocks hidden.
 */
class PartnerLandingPageTest extends TestCase
{
    use RefreshDatabase;

    /** Start from a known-empty table (the create migration seeds four rows). */
    protected function setUp(): void
    {
        parent::setUp();
        PartnerBenefit::query()->delete();
    }

    public function test_the_page_renders_for_a_guest_from_code_defaults(): void
    {
        $this->get(route('customer.partners'))
            ->assertOk()
            ->assertSeeText('Your skills.')->assertSeeText('Our customers.')
            ->assertSeeText('Pick your role')
            ->assertSeeText('Every question, one place')
            ->assertSee('<meta name="robots" content="index, follow">', false);
    }

    public function test_partners_is_no_longer_a_coming_soon_placeholder_key(): void
    {
        $this->assertNotContains('partners', \App\Http\Controllers\Customer\PageController::COMING_SOON_FEATURES);
    }

    public function test_nine_role_cards_come_from_the_registry_and_only_live_modules_are_live(): void
    {
        $html = $this->get(route('customer.partners'))->assertOk()->getContent();

        foreach (['Service professional', 'Delivery rider', 'Restaurant partner', 'Grocery store', 'Pharmacy', 'Cab driver', 'Hotel or stay host', 'Property or rental owner', 'Seller or shop'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertSame(9, substr_count($html, 'data-role="'));
        $this->assertSame(1, substr_count($html, '>Live<'));
        $this->assertSame(8, substr_count($html, '>Opening soon<'));
    }

    public function test_a_card_follows_the_registry_answer_read_live(): void
    {
        $roles = fn () => collect(PartnerPageData::roles())->keyBy('code');
        $this->assertFalse($roles()['parcel']['live']);

        Module::where('code', 'parcel')->update(['is_implemented' => true]);
        $country = Country::create(['name' => 'T', 'code' => 'ZZ', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true]);
        app(ModuleActivationService::class)->setActive('parcel', 'country', $country->id, true);

        // Whatever the registry answers for the global scope is exactly what the card shows.
        $this->assertSame(app(ModuleActivationService::class)->isActive('parcel'), $roles()['parcel']['live']);
        $this->assertTrue($roles()['service']['hands_off']);
        $this->assertFalse($roles()['parcel']['hands_off'], 'only the service role continues into the provider sign-up');
    }

    public function test_a_hidden_module_card_is_not_rendered(): void
    {
        Setting::set(P::key('modules_hidden'), json_encode(['taxi']));

        $this->get(route('customer.partners'))->assertOk()->assertDontSeeText('Cab driver')->assertSeeText('Seller or shop');
    }

    public function test_words_come_from_settings_not_the_view(): void
    {
        Setting::set(P::key('hero.title'), 'Partner with us today');
        Setting::set(P::key('steps'), json_encode([['title' => 'Only step', 'body' => 'Do the one thing.']]));

        $this->get(route('customer.partners'))->assertOk()
            ->assertSeeText('Partner with us today')->assertDontSeeText('Your skills.')
            ->assertSeeText('Only step')->assertDontSeeText('Upload your documents');
    }

    public function test_unconfirmed_claims_are_off_by_default_and_each_switch_shows_its_own_sentence(): void
    {
        $page = $this->get(route('customer.partners'))->assertOk();
        foreach (P::CLAIM_TEXT as $text) {
            $page->assertDontSeeText($text);
        }

        Setting::set(P::key('claims.joining_free'), '1');
        $this->get(route('customer.partners'))->assertSeeText('Joining is free.')->assertDontSeeText('We send you updates on WhatsApp.');
    }

    public function test_empty_blocks_are_hidden_and_configured_ones_show(): void
    {
        $this->get(route('customer.partners'))->assertOk()
            ->assertDontSeeText('Standard commission')->assertDontSee('Get the Android app')->assertDontSee('Get the iPhone app');

        Setting::set(P::key('commission.min'), '25');
        Setting::set(P::key('commission.max'), '30');
        Setting::set(P::key('store.android_url'), 'https://play.google.com/store/apps/details?id=x');
        Setting::set(P::key('payout_timing'), 'Payouts are processed after verification.');

        $this->get(route('customer.partners'))->assertOk()
            ->assertSeeText('Standard commission is 25 to 30 percent of the job value.')
            ->assertSee('Get the Android app')->assertDontSee('Get the iPhone app')
            ->assertSeeText('Payouts are processed after verification.');
    }

    public function test_the_faq_is_tabbed_and_empty_tabs_are_dropped(): void
    {
        $this->get(route('customer.partners'))->assertOk()
            ->assertSeeText('Everyone')->assertSeeText('Service professionals')->assertSeeText('Riders and drivers')->assertSeeText('Shops and restaurants');

        Setting::set(P::key('faq'), json_encode([['tab' => 'service', 'q' => 'Only question?', 'a' => 'Yes.']]));
        $this->get(route('customer.partners'))->assertOk()->assertSeeText('Only question?')->assertDontSeeText('Riders and drivers');
    }

    public function test_truth_rule_wording_is_present_and_the_old_unbacked_claims_are_gone(): void
    {
        $this->get(route('customer.partners'))->assertOk()
            ->assertSeeText('Payouts to your bank or UPI')
            ->assertSeeText('Go online when you want')
            ->assertDontSeeText('paid on time')->assertDontSeeText('Prices set up front');
    }

    public function test_the_page_no_longer_reads_the_cms_partner_benefits_table(): void
    {
        foreach (['Steady, well-paid work', 'Work on your schedule', 'Clear, on-time payouts', 'A verified, protected profile'] as $i => $title) {
            PartnerBenefit::create(['icon' => 'wallet', 'title' => $title, 'description' => 'old', 'sort_order' => $i, 'is_active' => true]);
        }

        $html = $this->get(route('customer.partners'))->assertOk()->getContent();

        foreach (P::defaultBenefits() as $tile) {
            $this->assertStringContainsString($tile['title'], $html);
        }
        foreach (['Steady, well-paid work', 'Work on your schedule', 'Clear, on-time payouts', 'A verified, protected profile'] as $old) {
            $this->assertStringNotContainsString($old, $html);
        }
    }

    public function test_tiles_saved_in_the_partner_page_screen_replace_the_defaults(): void
    {
        Setting::set(P::key('benefits'), json_encode([['icon' => 'star', 'color' => 'rose', 'title' => 'Own tile', 'body' => 'x']]));
        $this->get(route('customer.partners'))->assertOk()->assertSeeText('Own tile')->assertDontSeeText('Earnings you can see');
    }

    public function test_the_footer_links_to_the_partner_page(): void
    {
        $this->get(route('customer.home'))->assertOk()->assertSee(route('customer.partners'))->assertSeeText('Join as a Partner');
    }
}

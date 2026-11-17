<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\InfoPages\ContactSettings;
use App\Livewire\InfoPages\FranchiseSettings;
use App\Livewire\Partner\ApplyForm;
use App\Models\PartnerBenefit;
use App\Models\PartnerLead;
use App\Models\Setting;
use App\Support\InfoPages\ContactDetails;
use App\Support\InfoPages\FranchisePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-07: a Contact page and a Franchise page ("fully controlled by Super Admin only ... attractive,
 * trustable and informative"). Applying for a franchise uses the one /partners form (owner rule: one way in).
 */
class ContactAndFranchisePagesTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PartnerBenefit::query()->delete();
    }

    // ------------------------------------------------------------------ Franchise page

    public function test_the_franchise_page_shows_its_sections_and_applies_through_the_partner_form(): void
    {
        $html = $this->get('/franchise')->assertOk()->getContent();

        $this->assertStringContainsString('data-franchise-page', $html);
        $this->assertStringContainsString('Bring 1CallFix to your city', $html);
        $this->assertStringContainsString('Why a 1CallFix franchise', $html);
        $this->assertStringContainsString('data-franchise-steps', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html, 'questions are marked up for Google');
        $this->assertStringContainsString(e(FranchisePage::applyUrl()), $html);
        $this->assertStringContainsString('role=franchise', $html);
        $this->assertStringContainsString('data-share', $html, 'every public page can be shared');
    }

    public function test_nothing_is_invented_investment_stories_and_empty_numbers_stay_hidden(): void
    {
        $html = $this->get('/franchise')->assertOk()->getContent();

        $this->assertStringNotContainsString('From our franchise partners', $html, 'no stories until real ones are added');
        $this->assertStringNotContainsString('Cities open for franchise', $html, 'no city list until the Super Admin adds one');
        $this->assertStringNotContainsString('data-franchise-stats', $html, 'a fresh install has nothing to count, so no "0" numbers');
        $this->assertStringContainsString('We share them in writing', $html, 'investment is explained, not made up');
        $this->assertStringNotContainsString('data-franchise-talk', $html, 'no Talk button without a real phone or WhatsApp');
    }

    /** Owner 2026-11-14: better than a one-business franchise page — comparison, live businesses, calculator, phone bar. */
    public function test_the_comparison_table_and_phone_bar_show_and_the_calculator_waits_for_a_share(): void
    {
        $html = $this->get('/franchise')->assertOk()->getContent();

        $this->assertStringContainsString('data-franchise-compare', $html);
        $this->assertStringContainsString('One business, or all of them?', $html);
        $this->assertStringContainsString('data-franchise-bar', $html);
        $this->assertStringNotContainsString('data-franchise-calc', $html, 'no calculator until the Super Admin sets the share');

        Setting::set(FranchisePage::CONTENT, json_encode(['calc_share' => '12.5'] + FranchisePage::defaults()));
        $html = $this->get('/franchise')->assertOk()->getContent();
        $this->assertStringContainsString('data-franchise-calc', $html);
        $this->assertStringContainsString('12.5%', $html);
        $this->assertStringContainsString('not a promise of income', $html);
    }

    public function test_the_calculator_share_must_be_a_real_percent(): void
    {
        $this->assertArrayHasKey('calc_share', FranchisePage::validate(['calc_share' => 'ten'] + FranchisePage::defaults()));
        $this->assertArrayHasKey('calc_share', FranchisePage::validate(['calc_share' => '150'] + FranchisePage::defaults()));
        $this->assertArrayNotHasKey('calc_share', FranchisePage::validate(['calc_share' => '10'] + FranchisePage::defaults()));
        $this->assertArrayNotHasKey('calc_share', FranchisePage::validate(FranchisePage::defaults()));
    }

    /** Owner 2026-11-14: fee plans, payment steps and area table from the owner's sales plan, all Super Admin words. */
    public function test_plans_payment_and_areas_show_and_the_defaults_pass_the_admin_check(): void
    {
        $this->assertSame([], FranchisePage::validate(FranchisePage::defaults()), 'the shipped defaults can be saved as they are');

        $html = $this->get('/franchise')->assertOk()->getContent();
        $this->assertSame(3, substr_count($html, 'data-franchise-plan>'));
        foreach (['₹5.5 Lakhs', '₹20 Lakhs', '₹3.5 Lakhs', '₹1 Lakh each', 'Booking advance ₹50,000', 'Area Franchise', '20–25 km radius', 'data-franchise-payment', 'data-franchise-areas'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        Setting::set(FranchisePage::CONTENT, json_encode(['plans' => [], 'payment' => [], 'areas' => [], 'fee_all' => ''] + FranchisePage::defaults()));
        $html = $this->get('/franchise')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-franchise-plans', $html, 'emptied by the Super Admin = not shown');
    }

    /** Owner 2026-11-14: a franchisee may take only some businesses and pays for those. */
    public function test_the_business_picker_needs_two_live_businesses_and_number_fees(): void
    {
        $this->assertStringNotContainsString('data-franchise-picker', $this->get('/franchise')->getContent(), 'one live business: nothing to pick');

        $activation = app(\App\Services\ModuleActivationService::class);
        foreach (['parcel', 'food'] as $code) {
            \App\Models\Module::where('code', $code)->update(['is_implemented' => true]);
            $activation->setActive($code, 'franchise', 1, true);
        }
        $live = array_column(FranchisePage::liveBusinesses(), 'slug');
        $this->assertContains('parcel', $live);

        $html = $this->get('/franchise')->assertOk()->getContent();
        $this->assertStringContainsString('data-franchise-picker', $html);
        $this->assertStringContainsString('data-pick="parcel"', $html);
        $this->assertStringContainsString('all: 550000, two: 350000, addon: 100000, one: 0', $html);
        $this->assertStringContainsString('data-franchise-hero-tiles', $html);
        $this->assertStringContainsString('door-parcel', $html, 'the homepage picture of each business');
        $this->assertStringContainsString('data-franchise-business="food"', $html);

        $this->assertArrayHasKey('fee_two', FranchisePage::validate(['fee_two' => '3.5 lakh'] + FranchisePage::defaults()));
        Setting::set(FranchisePage::CONTENT, json_encode(['fee_all' => ''] + FranchisePage::defaults()));
        $this->assertStringNotContainsString('data-franchise-picker', $this->get('/franchise')->getContent(), 'no fees set = no picker');
    }

    /** A page the Super Admin already saved gets the new plan lines added, and nothing they wrote is changed. */
    public function test_the_plan_lines_migration_adds_to_saved_content_without_touching_admin_words(): void
    {
        $migration = require database_path('migrations/2026_11_17_100500_franchise_page_plan_lines.php');
        $migration->up(); // nothing saved: nothing to do
        $this->assertNull(Setting::get(FranchisePage::CONTENT));

        $old = 'It depends on the city. We share the investment and fees in writing on your call with our team, before you commit to anything.';
        Setting::set(FranchisePage::CONTENT, json_encode([
            'support' => [['text' => 'My own line']],
            'faq' => [['q' => 'How much does a franchise cost?', 'a' => $old], ['q' => 'Who can apply?', 'a' => 'My own answer']],
        ] + FranchisePage::defaults()));
        $migration->up();
        $migration->up(); // running twice adds nothing twice

        $p = FranchisePage::content();
        $this->assertSame('My own line', $p['support'][0]['text']);
        $this->assertCount(5, $p['support']);
        $this->assertSame('Franchise certificate and area rights', $p['support'][3]['text']);
        $this->assertStringContainsString('₹50,000', $p['faq'][0]['a']);
        $this->assertSame('My own answer', $p['faq'][1]['a']);
        $this->assertCount(3, $p['faq']);
        $this->assertSame('Can I run only one or two businesses?', $p['faq'][2]['q']);

        Setting::set(FranchisePage::CONTENT, json_encode(['faq' => [['q' => 'How much does a franchise cost?', 'a' => 'Ask us.']]] + FranchisePage::defaults()));
        $migration->up();
        $this->assertSame('Ask us.', FranchisePage::content()['faq'][0]['a'], 'an answer the Super Admin rewrote stays');
    }

    /** Owner 2026-11-14: what each business costs on your own, the FAQ in two columns, and the real business count. */
    public function test_the_on_your_own_section_the_two_column_faq_and_the_live_business_count(): void
    {
        $html = $this->get('/franchise')->assertOk()->getContent();
        $this->assertSame(7, substr_count($html, 'data-franchise-alone-card'));
        foreach (['Home services', '₹6–12 Lakhs', '₹75,000–2 L', 'Hotel booking', 'Rentals', 'Events &amp; tickets', 'data-franchise-alone-total', '₹30–59 Lakhs', 'The risks of starting from scratch'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertSame(2, substr_count($html, 'data-faq-column'));

        $activation = app(\App\Services\ModuleActivationService::class);
        foreach (['parcel', 'food', 'hotel', 'events', 'commerce'] as $code) {
            \App\Models\Module::where('code', $code)->update(['is_implemented' => true]);
            $activation->setActive($code, 'franchise', 1, true);
        }
        $live = count(FranchisePage::liveBusinesses());
        $this->assertGreaterThan(4, $live);
        $stat = collect(FranchisePage::liveStats())->firstWhere('label', 'businesses live on one app');
        $this->assertSame((string) $live, $stat['value'], 'the number counts every live business, not four fixed ones');

        Setting::set(FranchisePage::CONTENT, json_encode(['alone' => [], 'faq' => [['q' => 'One?', 'a' => 'Yes.']]] + FranchisePage::defaults()));
        $html = $this->get('/franchise')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-franchise-alone', $html);
        $this->assertSame(1, substr_count($html, 'data-faq-column'));
    }

    public function test_only_live_businesses_are_listed(): void
    {
        $this->assertSame([], array_filter(FranchisePage::liveBusinesses(), fn ($b) => ! in_array($b['label'], \App\Support\Modules::ALL, true)));
    }

    public function test_only_the_super_admin_can_open_and_save_the_franchise_page_screen(): void
    {
        $granted = $this->makeUserWithPermission('franchise_page.manage', 'global');
        Livewire::actingAs($granted)->test(FranchiseSettings::class)->assertForbidden();

        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(FranchiseSettings::class)
            ->set('c.hero_title', 'Run 1CallFix in Ongole')
            ->set('c.invest', [['label' => 'Investment', 'value' => '₹5 lakh']])
            ->set('c.cities', [['name' => 'Ongole']])
            ->call('save')->assertHasNoErrors();

        $html = $this->get('/franchise')->assertOk()->getContent();
        $this->assertStringContainsString('Run 1CallFix in Ongole', $html);
        $this->assertStringContainsString('₹5 lakh', $html);
        $this->assertStringContainsString('Ongole', $html);
        $this->assertDatabaseHas('settings', ['key' => FranchisePage::CONTENT]);
    }

    public function test_placeholders_empty_rows_and_a_blank_title_are_refused(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(FranchiseSettings::class)
            ->set('c.hero_title', '')
            ->set('c.earn_text', 'You earn [X] percent')
            ->set('c.faq', [['q' => 'A question?', 'a' => '']])
            ->call('save')
            ->assertHasErrors(['c.hero_title', 'c.earn_text', 'c.faq.0.a']);

        $this->assertNull(Setting::get(FranchisePage::CONTENT));
    }

    public function test_switched_off_the_page_is_gone_from_the_site_footer_and_partner_form(): void
    {
        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(FranchiseSettings::class)->set('enabled', false)->call('save');

        $this->get('/franchise')->assertNotFound();
        $this->assertStringNotContainsString(route('customer.franchise'), $this->get('/')->getContent());
        $this->assertNotContains('franchise', array_column(\App\Support\PartnerPage\PartnerPageData::roles(), 'code'));
    }

    public function test_a_franchise_application_is_a_new_lead_from_the_partner_form_with_its_own_thank_you(): void
    {
        $this->get('/partners')->assertOk()->assertSee('Franchise owner')->assertSee('Taking applications');

        Livewire::withQueryParams(['role' => 'franchise'])->test(ApplyForm::class)
            ->assertSet('role', 'franchise')
            ->assertSee('Send application')
            ->set('name', 'Ravi Kumar')->set('phone', '9876543210')->set('cityChoice', ApplyForm::OTHER_CITY)->set('city', 'Ongole')->set('consent', true)
            ->call('submit')
            ->assertSet('outcome', 'enquiry')
            ->assertSee('Your franchise application is in.');

        $lead = PartnerLead::sole();
        $this->assertSame('franchise', $lead->role);
        $this->assertSame(PartnerLead::STATUS_NEW, $lead->status, 'kept for the HQ team to call, never a sign-up hand-off');
        $this->assertNull(session('partner_lead'));
    }

    public function test_franchise_leads_show_by_name_in_partner_leads(): void
    {
        $this->assertSame('Franchise owner', \App\Livewire\PartnerPage\Leads::roleNames()['franchise']);
    }

    // ------------------------------------------------------------------ Contact page

    public function test_the_contact_page_without_details_says_so_and_links_to_help(): void
    {
        $html = $this->get('/contact')->assertOk()->getContent();

        $this->assertStringContainsString('Contact us', $html);
        $this->assertStringContainsString('data-contact-soon', $html);
        $this->assertStringContainsString(route('customer.help'), $html);
        $this->assertStringContainsString(route('customer.franchise'), $html);
        $this->get('/contact-us')->assertRedirect('/contact')->assertStatus(301);
    }

    public function test_only_the_super_admin_sets_the_contact_details_and_they_show_on_both_pages(): void
    {
        $granted = $this->makeUserWithPermission('contact_page.manage', 'global');
        Livewire::actingAs($granted)->test(ContactSettings::class)->assertForbidden();

        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(ContactSettings::class)
            ->set('v.email', 'not an email')->set('v.map_url', 'http://maps.example')
            ->call('save')->assertHasErrors(['v.email', 'v.map_url']);

        Livewire::actingAs($admin)->test(ContactSettings::class)
            ->set('v.phone', '+91 98765 43210')->set('v.whatsapp', '919876543210')->set('v.email', 'support@1callfix.com')
            ->set('v.address', "12 Main Road\nNellore")->set('v.franchise_email', 'franchise@1callfix.com')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('+91 98765 43210', ContactDetails::get('phone'));
        $html = $this->get('/contact')->assertOk()->getContent();
        $this->assertStringContainsString('href="tel:+919876543210"', $html);
        $this->assertStringContainsString('https://wa.me/919876543210', $html);
        $this->assertStringContainsString('mailto:support@1callfix.com', $html);
        $this->assertStringContainsString('data-contact-address', $html);

        $franchise = $this->get('/franchise')->assertOk()->getContent();
        $this->assertStringContainsString('data-franchise-talk', $franchise);
        $this->assertStringContainsString('mailto:franchise@1callfix.com', $franchise, 'franchise email wins on the franchise page');
    }

    public function test_both_pages_are_in_the_footer_and_the_sitemap(): void
    {
        $home = $this->get('/')->getContent();
        $this->assertStringContainsString('href="'.route('customer.contact').'"', $home);
        $this->assertStringContainsString('href="'.route('customer.franchise').'"', $home);

        $xml = $this->get('/sitemap-static.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/contact</loc>', $xml);
        $this->assertStringContainsString('/franchise</loc>', $xml);
    }
}

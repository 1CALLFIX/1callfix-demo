<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\City;
use App\Models\Country;
use App\Models\Franchise;
use App\Models\KycDocumentRequirement;
use App\Models\Module;
use App\Models\PartnerBenefit;
use App\Models\Setting;
use App\Services\ModuleActivationService;
use App\Support\PartnerPage\PartnerFaq;
use App\Support\PartnerPage\PartnerPageData;
use App\Support\PartnerPage\PartnerPageSettings as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner 2026-11-22: the /partners FAQ answers with live data. Each answer is read from where the app reads it (role
 * status, live cities, Admin → KYC documents, charges and limits), so it follows every admin change and never says
 * a role is "not open yet" after it opened (the old riders / shops answers did).
 */
class PartnerLiveFaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PartnerBenefit::query()->delete();
    }

    private function page(): string
    {
        return html_entity_decode(strip_tags($this->get(route('customer.partners'))->assertOk()->getContent()), ENT_QUOTES);
    }

    /** @return array<string, string> question => answer */
    private function faq(): array
    {
        return collect(PartnerFaq::live(PartnerPageData::roles()))->mapWithKeys(fn ($i) => [$i['q'] => $i['a']])->all();
    }

    private function openModule(string $code): void
    {
        Module::where('code', $code)->update(['is_implemented' => true]);
        app(ModuleActivationService::class)->setActive($code, 'franchise', 1, true);
    }

    public function test_live_answers_show_by_default_and_the_stale_not_open_yet_answers_are_gone(): void
    {
        $this->assertTrue(P::on('show.live_faq'));
        $text = $this->page();

        $this->assertStringContainsString('Live data', $text);
        $this->assertStringContainsString('Which roles are open right now?', $text);
        $this->assertStringContainsString('Can I choose my own hours?', $text, 'the admin questions still show under the live ones');
        $this->assertStringNotContainsString('These roles are not open yet', $text);
        $this->assertStringContainsString('Shops, stays and owners', $text);
    }

    public function test_the_roles_answer_follows_the_registry(): void
    {
        $a = $this->faq()['Which roles are open right now?'];
        $this->assertStringContainsString('Apply now', $a);
        $this->assertStringContainsString('Service professional', $a);
        $this->assertMatchesRegularExpression('/Opening soon: .*Restaurant partner/s', $a);

        $this->openModule('food');
        $this->openModule('commerce');

        $faq = $this->faq();
        $this->assertMatchesRegularExpression('/Apply now: .*Restaurant partner.*Opening soon/s', $faq['Which roles are open right now?']);
        $this->assertMatchesRegularExpression('/Apply now: Restaurant partner and Seller or shop\./', $faq['Which businesses can join now?']);
        $this->assertStringContainsString('FSSAI licence or registration', $faq['What does a restaurant partner need?']);
        $this->assertArrayHasKey('What does a seller or shop need?', $faq);

        $this->openModule('events');
        $this->assertArrayHasKey('What does an event organiser need?', $this->faq(), 'an, not a, before a vowel');
        $this->assertArrayNotHasKey('What does a grocery store need?', $faq, 'a waitlisted business gets no document answer yet');
    }

    public function test_only_live_cities_are_named(): void
    {
        $country = Country::create(['name' => 'India T', 'code' => 'IT', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true]);
        foreach (['Nelloreville' => 'active', 'Gunturpet' => 'inactive'] as $name => $status) {
            $city = City::create(['country_id' => $country->id, 'name' => $name, 'slug' => strtolower($name), 'is_active' => true]);
            Franchise::create(['name' => $name.' F', 'slug' => strtolower($name).'-f', 'city' => $name, 'country_id' => $country->id, 'city_id' => $city->id,
                'commission_model' => 'revenue_share', 'commission_value' => 10, 'platform_fee_percent' => 5, 'status' => $status]);
        }

        $a = $this->faq()['Which cities can I work in?'];
        $this->assertStringContainsString('Nelloreville', $a);
        $this->assertStringNotContainsString('Gunturpet', $a, 'a city whose franchise is not active is not live');
        $this->assertStringContainsString('in Nelloreville', $this->faq()['Which roles are open right now?']);
    }

    public function test_document_lists_follow_admin_kyc_documents(): void
    {
        $a = $this->faq()['What documents does a service professional need?'];
        $this->assertStringContainsString('Needed: Government ID proof', $a);
        $this->assertStringContainsString('If you have them, also add:', $a);
        $this->assertStringContainsString('Police verification', $a);

        KycDocumentRequirement::where('applicable_type', 'provider')->where('document_type', 'police_verification')->update(['is_required' => true]);
        $this->assertMatchesRegularExpression('/Needed: [^.]*Police verification/', $this->faq()['What documents does a service professional need?']);

        KycDocumentRequirement::where('applicable_type', 'provider')->where('document_type', 'police_verification')->update(['is_active' => false]);
        $this->assertStringNotContainsString('Police verification', $this->faq()['What documents does a service professional need?']);

        Setting::set('kyc.max_document_size_mb', '5');
        $this->assertStringContainsString('up to 5 MB each', $this->faq()['What documents do I need?']);
    }

    public function test_charges_and_limits_are_read_from_their_settings_and_never_invented(): void
    {
        Setting::set('cancellation.visit_fee_type', 'flat');
        Setting::set('cancellation.visit_fee_value', '149');
        Setting::set('cancellation.no_show_wait_minutes', '15');
        Setting::set('cancellation.no_show_call_attempts', '2');
        Setting::set('cancellation.interim_cap_percent', '40');
        $faq = $this->faq();
        $this->assertStringContainsString('visit charge of ₹149', $faq['When does the customer pay a visit charge?']);
        $this->assertStringContainsString('wait 15 minutes and call 2 times', $faq['When does the customer pay a visit charge?']);
        $this->assertStringContainsString('the visit charge is never added', $faq['When does the customer pay a visit charge?']);
        $this->assertStringContainsString('at most 40% of the booking total', $faq['What if I start a job but cannot finish it?']);

        Setting::set('cancellation.visit_fee_value', '199');
        $this->assertStringContainsString('₹199', $this->faq()['When does the customer pay a visit charge?']);

        Setting::set('cancellation.visit_fee_value', '0');
        Setting::set('cancellation.interim_cap_percent', '');
        $faq = $this->faq();
        $this->assertArrayNotHasKey('When does the customer pay a visit charge?', $faq);
        $this->assertArrayNotHasKey('What if I start a job but cannot finish it?', $faq);
        $this->assertArrayNotHasKey('Who can help me apply?', $faq, 'no support number typed = no question');
        $this->assertStringNotContainsString('%', $faq['How much commission does 1CallFix take?'], 'no commission number until the admin sets one');

        Setting::set(P::key('commission.min'), '10');
        Setting::set(P::key('commission.max'), '20');
        Setting::set(P::key('support_phones'), '+91 90000 00000');
        P::forgetCache();
        $faq = $this->faq();
        $this->assertStringContainsString('10 to 20 percent', $faq['How much commission does 1CallFix take?']);
        // Owner 2026-11-26: support numbers are written the easy way (ContactDetails::pretty).
        $this->assertStringContainsString('+91 9000 000 000', $faq['Who can help me apply?']);
    }

    public function test_rider_answers(): void
    {
        Setting::set('parcel.max_weight_kg', '10');
        Setting::set('parcel.max_distance_km', '15');
        $faq = $this->faq();
        $this->assertStringContainsString('Parcels up to 10 kg', $faq['What kind of parcels will I deliver?']);
        $this->assertStringContainsString('within 15 km', $faq['What kind of parcels will I deliver?']);
        $this->assertStringContainsString('Government ID proof', $faq['What documents does a delivery rider need?']);
        $this->assertStringStartsWith('Cab rides are opening soon.', $faq['Can I join as a cab driver?']);
        $this->assertArrayNotHasKey('When will cab driver jobs open?', $faq, 'no question promising a date');
        // Points to delivery rider work exactly when that role is really open (same live answer as its own question).
        $this->assertSame(str_starts_with($faq['Are delivery rider jobs open?'], 'Yes.'), str_contains($faq['Can I join as a cab driver?'], 'Want to earn now? Delivery rider jobs are open'));
    }

    public function test_the_switch_turns_the_live_answers_off(): void
    {
        Setting::set(P::key('show.live_faq'), '0');
        $text = $this->page();

        $this->assertStringNotContainsString('Live data', $text);
        $this->assertStringNotContainsString('Which roles are open right now?', $text);
        $this->assertStringContainsString('Can I choose my own hours?', $text);
    }
}

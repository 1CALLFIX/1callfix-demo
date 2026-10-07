<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Partner\ApplyForm;
use App\Livewire\Provider\Auth\Register;
use App\Models\City;
use App\Models\Country;
use App\Models\PartnerLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Feature\Support\RebuiltAuthHelpers;
use Tests\TestCase;

/** REF 1CF-PARTNER-PAGE-001: the city is a select of active cities plus "My city is not listed" with a text box. */
class PartnerApplyFormCityTest extends TestCase
{
    use RebuiltAuthHelpers;
    use RefreshDatabase;

    private Country $country;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeFirebase();
        RateLimiter::clear('x');
        $this->country = Country::create(['name' => 'Testland', 'code' => 'TL', 'currency_code' => 'INR', 'default_timezone' => 'Asia/Kolkata', 'is_active' => true]);
        City::create(['country_id' => $this->country->id, 'name' => 'Nellore', 'slug' => 'nellore', 'is_active' => true]);
        City::create(['country_id' => $this->country->id, 'name' => 'Sri City', 'slug' => 'sri-city', 'is_active' => true]);
        City::create(['country_id' => $this->country->id, 'name' => 'Dormant Town', 'slug' => 'dormant-town', 'is_active' => false]);
        City::create(['country_id' => $this->country->id, 'name' => '[QA] Demo City', 'slug' => 'qa-demo-city', 'is_active' => true]);
    }

    private function form(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(ApplyForm::class)->set('role', 'service')->set('name', 'Ramesh Kumar')->set('phone', $this->randomPhone())->set('consent', true);
    }

    public function test_the_select_lists_active_cities_with_their_slug_and_the_not_listed_option(): void
    {
        $this->get(route('customer.partners'))->assertOk()
            ->assertSeeHtml('<option value="nellore">Nellore</option>')
            ->assertSeeHtml('<option value="sri-city">Sri City</option>')
            ->assertSee('My city is not listed')
            ->assertDontSee('Dormant Town')->assertDontSee('Demo City');
    }

    public function test_a_listed_city_saves_its_stored_name_and_hands_its_slug_to_the_sign_up(): void
    {
        // The typed text box is ignored when a listed city is chosen: the name comes from the database.
        $this->form()->set('cityChoice', 'sri-city')->set('city', 'Spoofed Name')->call('submit')
            ->assertHasNoErrors()->assertSet('outcome', 'handoff');

        $lead = PartnerLead::sole();
        $this->assertSame('Sri City', $lead->city);
        $this->assertSame(['id' => $lead->id, 'role' => 'service', 'city' => 'sri-city'], session('partner_lead'));

        Livewire::test(Register::class)->assertSet('leadId', $lead->id)->assertSet('address', 'Sri City');
    }

    public function test_a_typed_city_saves_the_text_and_prefills_nothing(): void
    {
        $this->form()->set('cityChoice', ApplyForm::OTHER_CITY)->set('city', 'Ongole')->call('submit')
            ->assertHasNoErrors()->assertSet('outcome', 'handoff');

        $lead = PartnerLead::sole();
        $this->assertSame('Ongole', $lead->city);
        $this->assertSame(['id' => $lead->id, 'role' => 'service', 'city' => null], session('partner_lead'));

        Livewire::test(Register::class)->assertSet('leadId', $lead->id)->assertSet('address', '');
    }

    public function test_the_server_validates_the_city(): void
    {
        $this->assertSame(['Choose your city.'], $this->form()->set('cityChoice', '')->call('submit')->errors()->get('cityChoice'));
        $this->form()->set('cityChoice', 'no-such-city')->call('submit')->assertHasErrors(['cityChoice']);
        $this->form()->set('cityChoice', 'dormant-town')->call('submit')->assertHasErrors(['cityChoice']);
        $this->form()->set('cityChoice', ApplyForm::OTHER_CITY)->call('submit')->assertHasErrors(['city']);
        $this->form()->set('cityChoice', ApplyForm::OTHER_CITY)->set('city', '<script>')->call('submit')->assertHasErrors(['city']);
        $this->assertSame(0, PartnerLead::count());
    }

    public function test_without_the_cities_slug_column_the_page_still_renders_with_a_free_text_city_only(): void
    {
        Schema::partialMock()->shouldReceive('hasColumn')->with('cities', 'slug')->andReturn(false);

        $this->get(route('customer.partners'))->assertOk()
            ->assertDontSee('<select id="pl-city"', false)
            ->assertDontSee('My city is not listed')
            ->assertSeeHtml('id="pl-city-other"');

        // A typed city still saves, and the sign-up link holds with no prefill (and no query on the missing column).
        $this->form()->set('city', 'Ongole')->call('submit')->assertHasNoErrors()->assertSet('outcome', 'handoff');
        $lead = PartnerLead::sole();
        $this->assertSame('Ongole', $lead->city);
        $this->assertSame(['id' => $lead->id, 'role' => 'service', 'city' => null], session('partner_lead'));
        Livewire::test(Register::class)->assertSet('leadId', $lead->id)->assertSet('address', '');
    }
}

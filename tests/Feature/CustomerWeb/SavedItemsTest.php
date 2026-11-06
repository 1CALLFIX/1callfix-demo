<?php

namespace Tests\Feature\CustomerWeb;

use App\Livewire\Customer\Account\Saved;
use App\Models\SavedItem;
use App\Models\User;
use App\Support\SafeHtml;
use App\Support\SavedItems;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\CustomerWeb\Support\CatalogFixtures;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Owner 2026-11-07 (Flipkart / Meesho / Urban Company screenshots): a round ♡ Like and a round Share on the corner of
 * every item photo. ♡ saves the item to Account → Saved; a visitor is sent to sign-in and back.
 */
class SavedItemsTest extends TestCase
{
    use BookingFixtureHelpers;
    use CatalogFixtures;
    use LiveCity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->liveCity();
        SavedItems::flush();
    }

    private function ticketFrom(string $html): string
    {
        $this->assertSame(1, preg_match("/ticket: '([^']+)'/", $html, $m), 'the heart carries a sealed ticket');

        return stripslashes($m[1]);
    }

    public function test_the_service_photo_carries_a_heart_and_a_round_share_and_a_visitor_heart_goes_to_sign_in(): void
    {
        $service = $this->makeService($this->makeCategory(), ['name' => 'Split AC Jet Service']);
        $html = $this->get(\App\Support\Seo\PublicUrl::service($service))->assertOk()->getContent();

        $this->assertStringContainsString('data-item-actions', $html);
        $this->assertSame(1, substr_count($html, 'data-save-button'));
        $this->assertStringContainsString('aria-label="Share Split AC Jet Service"', $html);
        $this->assertStringContainsString('/login?intended=', $html, 'a visitor signs in first');
        $this->assertStringNotContainsString('>Share<', $html, 'the round button on the photo replaces the old pill');
    }

    public function test_a_customer_saves_and_unsaves_and_sees_it_on_the_saved_page(): void
    {
        $user = $this->makeCustomer();
        $service = $this->makeService($this->makeCategory(), ['name' => 'Fan Repair']);
        $url = \App\Support\Seo\PublicUrl::service($service);

        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-saved="0"', $html);
        $ticket = $this->ticketFrom($html);

        $this->postJson(route('customer.saved.toggle'), ['ticket' => $ticket])->assertOk()->assertJson(['saved' => true]);
        $row = SavedItem::where('user_id', $user->id)->sole();
        $this->assertSame('service:'.$service->id, $row->item_key);
        $this->assertSame('Fan Repair', $row->title);
        $this->assertSame(parse_url($url, PHP_URL_PATH), $row->url);

        SavedItems::flush();
        $this->assertStringContainsString('data-saved="1"', $this->get($url)->getContent(), 'the heart stays red');

        Livewire::actingAs($user)->test(Saved::class)->assertSee('Fan Repair')->assertSeeHtml('href="'.$row->url.'"');
        $this->get(route('customer.saved'))->assertOk()->assertSee('Fan Repair');

        $this->postJson(route('customer.saved.toggle'), ['ticket' => $ticket])->assertOk()->assertJson(['saved' => false]);
        $this->assertSame(0, SavedItem::count());
        Livewire::actingAs($user)->test(Saved::class)->assertSee('Nothing saved yet');
    }

    public function test_remove_on_the_saved_page_touches_only_your_own_list(): void
    {
        $me = $this->makeCustomer();
        $other = $this->makeCustomer();
        $mine = SavedItem::create(['user_id' => $me->id, 'item_key' => 'product:1', 'title' => 'Mine', 'url' => '/a']);
        $theirs = SavedItem::create(['user_id' => $other->id, 'item_key' => 'product:1', 'title' => 'Theirs', 'url' => '/a']);

        Livewire::actingAs($me)->test(Saved::class)->assertDontSee('Theirs')->call('remove', $theirs->id)->call('remove', $mine->id);

        $this->assertDatabaseMissing('saved_items', ['id' => $mine->id]);
        $this->assertDatabaseHas('saved_items', ['id' => $theirs->id]);
    }

    public function test_only_a_ticket_the_page_made_can_be_saved_and_never_a_link_to_another_site(): void
    {
        $user = $this->makeCustomer();

        $this->actingAs($user)->postJson(route('customer.saved.toggle'), ['ticket' => 'made-up'])->assertStatus(422);
        $evil = SavedItems::ticket('product:9', 'X', '//evil.example/x', null);
        $this->postJson(route('customer.saved.toggle'), ['ticket' => $evil])->assertStatus(422);
        $this->assertSame(0, SavedItem::count());

        auth()->logout();
        $this->postJson(route('customer.saved.toggle'), ['ticket' => SavedItems::ticket('product:9', 'X', '/x', null)]);
        $this->assertSame(0, SavedItem::count(), 'a visitor cannot save');
    }

    public function test_the_saved_page_is_on_the_account_page_and_in_the_menu(): void
    {
        $user = $this->makeCustomer();

        $this->actingAs($user)->get(route('customer.account'))->assertOk()->assertSee(route('customer.saved'), false);
        $this->get(route('customer.saved'))->assertOk()->assertSee('Saved');
        auth()->logout();
        $this->get(route('customer.saved'))->assertRedirect();
    }

    public function test_old_app_html_descriptions_show_formatted_never_as_code(): void
    {
        $html = '<h3 style="color:Green;">INSTRUCTIONS:</h3><ul style="text-align:justify"><li style="margin: 2px 0;">Visit charge ₹199</li>'
            .'<li style="x><script>alert(1)</script>">ok</li></ul><img src=x onerror=alert(1)><a href="javascript:x">link</a>';
        $clean = SafeHtml::clean($html);

        $this->assertSame('<h3>INSTRUCTIONS:</h3><ul><li>Visit charge ₹199</li><li>ok</li></ul>link', $clean);
        $this->assertFalse(SafeHtml::isHtml('Plain words, 2 < 3'));

        $service = $this->makeService($this->makeCategory(), ['description' => $html]);
        $page = $this->get(\App\Support\Seo\PublicUrl::service($service))->assertOk();
        $page->assertSee('<h3>INSTRUCTIONS:</h3>', false)->assertDontSee('&lt;h3', false)->assertDontSee('onerror', false);
    }
}

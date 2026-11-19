<?php

namespace Tests\Feature\SecondHand;

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
use App\Support\PostTypes;
use App\Support\Seo\ReservedSlugs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\LiveCity;
use Tests\TestCase;

/**
 * Second-hand (owner 2026-11-19), Stage 3: /{city}/second-hand and its item pages (clean "second-hand-..." links,
 * /used 301s here, a sold page stays up but is not indexed), the Second-hand door, the sitemap, and the team's
 * People's posts queue with a For rent / Second-hand tab. Wording never says "sale", which is shop discounts here.
 */
class SecondHandPagesTest extends TestCase
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
        Setting::set('secondhand.enabled', '1', 'zone', $this->zone->id);
    }

    private function person(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Ravi', 'phone' => '9'.fake()->unique()->numerify('#########'),
            'role' => 'customer', 'status' => 'active', 'phone_verified_at' => now(),
        ]);
    }

    private function listing(array $extra = [], string $deal = PostTypes::SELL): RentalItem
    {
        $name = $extra['name'] ?? 'Godrej almirah';
        $title = RentalItem::makeTitle($name, $this->zone->name, $deal);

        return RentalItem::create($extra + [
            'user_id' => $this->person()->id, 'franchise_id' => $this->franchise->id, 'zone_id' => $this->zone->id, 'deal' => $deal,
            'name' => $name, 'title' => $title, 'slug' => RentalItem::uniqueSlug($title), 'category' => 'Furniture',
            'condition' => 'Good', 'price' => 6500, 'price_unit' => $deal === PostTypes::SELL ? null : 'Day', 'negotiable' => $deal === PostTypes::SELL,
            'has_bill' => true, 'about' => 'Steel, two doors.', 'landmark' => 'Near RTC bus stand',
            'cover_image' => 'https://example.test/cover.jpg', 'photos' => ['https://example.test/a.jpg'],
            'status' => 'approved', 'is_active' => true, 'approved_at' => now(), 'submitted_at' => now()->subHours(3),
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function test_city_page_lists_only_second_hand_posts_and_is_off_until_switched_on(): void
    {
        $almirah = $this->listing();
        $this->listing(['name' => 'Cot', 'category' => 'Furniture', 'status' => 'in_review', 'is_active' => false]);
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);
        Setting::set('rental.kind.items', '1');
        $this->listing(['name' => 'Sony camera', 'category' => 'Electronics and cameras'], PostTypes::RENT);

        $page = $this->get(route('customer.secondhand.home', $this->city->slug))->assertOk()
            ->assertSee('Second-hand near you')->assertSee('Godrej almirah')->assertSee('₹6,500')
            ->assertDontSee('Sony camera')->assertDontSee('Cot')->assertSee('Post your used things')
            ->assertSee(route('customer.secondhand-items.create'), false)->assertSee(PostTypes::url($almirah, $this->city->slug), false)
            ->assertSee('<title>Second-hand things in Nellore', false);
        $this->assertStringNotContainsString('rentals', $this->crumbOf((string) $page->getContent()), 'Second-hand is not under Rentals.');
        $this->get(route('customer.rent.home', $this->city->slug))->assertOk()->assertSee('Sony camera')->assertDontSee('Godrej almirah');

        Setting::set('secondhand.enabled', '0', 'zone', $this->zone->id);
        $this->get(route('customer.secondhand.home', $this->city->slug))->assertNotFound();
        $this->get(route('customer.secondhand.show', [$this->city->slug, $almirah->slug]))->assertNotFound();
    }

    public function test_used_301s_to_second_hand_with_the_query_string_kept(): void
    {
        $item = $this->listing();
        $this->get('/'.$this->city->slug.'/used?utm_source=wa')->assertStatus(301)->assertRedirect('/'.$this->city->slug.'/second-hand?utm_source=wa');
        $this->get('/'.$this->city->slug.'/used/'.$item->slug)->assertStatus(301)->assertRedirect('/'.$this->city->slug.'/second-hand/'.$item->slug);
        $this->assertTrue(ReservedSlugs::isReserved('second-hand'));
        $this->assertTrue(ReservedSlugs::isReserved('used'));
    }

    public function test_item_page_shows_price_can_be_discussed_bill_and_used_condition_and_the_contact_panel(): void
    {
        $item = $this->listing();
        $this->assertSame('second-hand-godrej-almirah-in-'.Str::slug($this->zone->name), $item->slug);
        $this->get(route('customer.secondhand.show', [$this->city->slug, $item->slug]))->assertOk()
            ->assertSee($item->title)->assertSee('Price can be discussed')->assertSee('Bill available')
            ->assertSee('UsedCondition', false)->assertDontSee('Deposit')->assertDontSee($item->user->phone)
            ->assertSee('Never pay before you have seen it');

        $customer = $this->person();
        Livewire::actingAs($customer)->test(Contact::class, ['itemId' => $item->id])
            ->call('showPhone')->assertSee('Price can be discussed')->assertSee($item->user->phone)
            ->assertSee(rawurlencode('Is it still available?'), false);
    }

    public function test_a_sold_page_stays_up_saying_sold_but_leaves_the_list_and_is_not_indexed(): void
    {
        $item = $this->listing();
        app(RentalItemService::class)->markSold($item->user, $item);

        $this->get(route('customer.secondhand.show', [$this->city->slug, $item->slug]))->assertOk()
            ->assertSee('This has been sold')->assertSee('See more second-hand things')->assertSee('noindex', false)
            ->assertSee('SoldOut', false)->assertDontSee('rcontact-'.$item->id, false);
        $this->get(route('customer.secondhand.home', $this->city->slug))->assertOk()->assertDontSee('Godrej almirah');
    }

    public function test_a_post_opened_under_the_other_kinds_address_301s_to_its_own(): void
    {
        app(ModuleActivationService::class)->setActive(Modules::RENTAL, 'franchise', $this->franchise->id, true);
        Setting::set('rental.kind.items', '1');
        $sale = $this->listing();
        $rent = $this->listing(['name' => 'Sony camera', 'category' => 'Electronics and cameras'], PostTypes::RENT);

        $this->get('/'.$this->city->slug.'/rent/'.$sale->slug.'?utm_source=g')->assertStatus(301)
            ->assertRedirect(route('customer.secondhand.show', [$this->city->slug, $sale->slug]).'?utm_source=g');
        $this->get('/'.$this->city->slug.'/second-hand/'.$rent->slug)->assertStatus(301)
            ->assertRedirect(route('customer.rent.show', [$this->city->slug, $rent->slug]));
        $this->get('/'.$this->city->slug.'/second-hand/no-such-thing')->assertNotFound();
    }

    public function test_the_city_hub_shows_the_second_hand_door_and_the_sitemap_lists_real_posts(): void
    {
        $item = $this->listing();
        $this->get(route('customer.city.show', $this->city->slug))->assertOk()
            ->assertSee('Second-hand')->assertSee('1 thing near you')->assertSee(route('customer.secondhand.home', $this->city->slug), false);

        $test = $this->listing(['name' => 'Test cot']);
        $test->user->update(['email' => 'test.secondhand@1callfix.test']);
        $map = $this->get(route('customer.sitemap.city', $this->city->slug))->assertOk()->getContent();
        $this->assertStringContainsString('/'.$this->city->slug.'/second-hand</loc>', $map);
        $this->assertStringContainsString('/'.$this->city->slug.'/second-hand/'.$item->slug.'</loc>', $map);
        $this->assertStringNotContainsString($test->slug, $map, 'Test accounts never reach Google.');
    }

    public function test_the_team_queue_has_a_second_hand_tab_and_badges(): void
    {
        $sale = $this->listing(['status' => 'in_review', 'is_active' => false]);
        $rent = $this->listing(['name' => 'Sony camera', 'status' => 'in_review', 'is_active' => false], PostTypes::RENT);
        $team = $this->makeUserWithPermission('rental_items.manage', 'franchise', $this->franchise->id);

        Livewire::actingAs($team)->test(Manage::class)->assertSee("People's posts", false)->assertSee('Godrej almirah')->assertSee('Sony camera')
            ->assertSee('Second-hand')->assertSee('For rent')->assertSee('₹6,500 · can be discussed')
            ->set('typeFilter', 'sell')->assertSee('Godrej almirah')->assertDontSee('Sony camera')
            ->call('approve', $sale->id)->assertSee('Approved. Customers see it now.');
        $this->assertTrue(RentalItemService::visible($sale->fresh()));
        $this->assertSame('in_review', $rent->fresh()->status);
    }

    private function crumbOf(string $html): string
    {
        return preg_match('#<nav aria-label="Breadcrumb"[^>]*>(.*?)</nav>#s', $html, $m) ? strtolower(strip_tags($m[1])) : '';
    }
}

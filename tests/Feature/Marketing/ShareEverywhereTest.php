<?php

namespace Tests\Feature\Marketing;

use App\Livewire\Marketing\SocialPosts;
use App\Livewire\Seller\Dashboard as SellerDashboard;
use App\Models\Setting;
use App\Models\SocialPost;
use App\Services\Marketing\AutoPoster;
use App\Services\Marketing\ItemPost;
use App\Services\Marketing\MetaGraph;
use App\Services\Marketing\PartnerBusinesses;
use App\Services\Marketing\SocialPostPlanner;
use App\Support\SavedItems;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\Feature\Support\MarketplaceFixtureHelpers;
use Tests\TestCase;

/**
 * Owner 2026-11-22: "each and every single product can also be posted … even a franchisee's will also social post
 * section … every shop, hotel, user, businesses". 📣 on every item photo for marketing staff, franchise owners plan
 * posts for their city (HQ approves before the main pages), partners get "Share your business" on their dashboard.
 */
class ShareEverywhereTest extends TestCase
{
    use BookingFixtureHelpers;
    use MarketplaceFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private array $w;

    protected function setUp(): void
    {
        parent::setUp();
        [, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $city->update(['slug' => 'nellore', 'name' => 'Nellore']);
        $this->activateMarketplaceModuleFor($franchise);
        $store = $this->makeStore($franchise, $zone, null, ['name' => 'Lakshmi Stores']);
        $this->w = compact('city', 'franchise', 'zone', 'store');
    }

    public function test_an_item_link_becomes_a_ready_post_with_its_module_and_city(): void
    {
        $ticket = SavedItems::ticket('product:9', 'Cotton Shirt', '/nellore/shop/lakshmi-stores/cotton-shirt', 'https://1callfix.com/storage/p.jpg');
        $post = ItemPost::fromTicket($ticket);

        $this->assertSame('Cotton Shirt', $post['title']);
        $this->assertSame('shop', $post['module']);
        $this->assertSame($this->w['city']->id, $post['city_id']);
        $this->assertStringContainsString('Cotton Shirt in Nellore', $post['caption']);
        $this->assertSame(['food', null], [ItemPost::place('/nellore/food/cake-house#dish-3')[0], ItemPost::place('/about')[0]]);
        $this->assertNull(ItemPost::fromTicket('forged'));
    }

    public function test_the_post_button_shows_only_to_marketing_staff_and_opens_a_filled_editor(): void
    {
        $page = route('customer.shop.store', ['nellore', $this->w['store']->slug]);
        $this->get($page)->assertOk()->assertDontSee('data-post-button', false);
        $this->actingAs($this->makeCustomer())->get($page)->assertOk()->assertDontSee('data-post-button', false);

        $admin = $this->makeSuperAdmin();
        $html = $this->actingAs($admin)->get($page)->assertOk()->assertSee('data-post-button', false)->getContent();
        preg_match('#href="([^"]*marketing/posts\?item=[^"]+)"[^>]*data-post-button#', $html, $m);
        $this->assertNotEmpty($m, 'the 📣 links to Social posts with the item');
        parse_str((string) parse_url(html_entity_decode($m[1]), PHP_URL_QUERY), $q);

        Livewire::withQueryParams(['item' => $q['item']])->actingAs($admin)->test(SocialPosts::class)
            ->assertSet('editing', true)->assertSet('title', 'Lakshmi Stores')->assertSet('module', 'shop')
            ->assertSet('cityId', $this->w['city']->id)->assertSet('linkUrl', '/nellore/shop/'.$this->w['store']->slug)
            ->assertSee('Post ready from');
    }

    public function test_franchise_owners_can_plan_posts_and_hq_approves_before_they_go_out(): void
    {
        $roleId = DB::table('roles')->where('slug', 'franchise_owner')->value('id');
        $permId = DB::table('permissions')->where('slug', 'marketing.posts.manage')->value('id');
        $this->assertTrue(DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permId)->exists());

        $owner = $this->makeUserWithPermission('marketing.posts.manage', 'franchise', $this->w['franchise']->id);
        Livewire::actingAs($owner)->test(SocialPosts::class)
            ->call('newPost')->set('title', 'Nellore Diwali')->set('captionEn', 'Diwali offers in Nellore')
            ->set('cityId', $this->w['city']->id)->set('platforms', ['facebook'])
            ->set('day', CarbonImmutable::now(SocialPostPlanner::zone())->toDateString())->set('time', '23:59')
            ->call('save', true)->assertHasNoErrors()->assertSee('Sent to HQ for approval');

        $post = SocialPost::where('title', 'Nellore Diwali')->firstOrFail();
        $this->assertSame('approval', $post->status);
        $this->assertSame($this->w['franchise']->id, $post->franchise_id);

        // Nothing goes out while it waits, even with Facebook connected.
        Setting::set(MetaGraph::APP_ID, '1');
        Setting::set(MetaGraph::APP_SECRET, Crypt::encryptString(str_repeat('a', 32)));
        app(MetaGraph::class)->savePage(['id' => '111', 'name' => 'P', 'token' => 'T', 'ig_id' => null, 'ig_username' => null]);
        Http::fake();
        app(AutoPoster::class)->publishDue();
        Http::assertNothingSent();

        Livewire::actingAs($owner)->test(SocialPosts::class)->assertSee('waiting for HQ approval')->call('approve', $post->id)->assertForbidden();

        $hq = $this->makeSuperAdmin();
        Livewire::actingAs($hq)->test(SocialPosts::class)->assertSee('Waiting for your approval (1)')
            ->call('sendBack', $post->id)->assertSee('Write a short note');
        $this->assertSame('approval', $post->fresh()->status);

        $post->forceFill(['scheduled_for' => now()->subHours(2)])->save();
        Livewire::actingAs($hq)->test(SocialPosts::class)->call('approve', $post->id)->assertSee('Approved');
        $this->assertSame('scheduled', $post->fresh()->status);
        $this->assertTrue($post->fresh()->scheduled_for->lte(now()), 'approved after its time: goes out at the next run');

        $other = SocialPost::create(['title' => 'Second', 'caption_en' => 'x', 'platforms' => ['facebook'], 'status' => 'approval',
            'scheduled_for' => now()->addDay(), 'source' => 'manual', 'franchise_id' => $this->w['franchise']->id, 'city_id' => $this->w['city']->id]);
        Livewire::actingAs($hq)->test(SocialPosts::class)->set("sendBackNotes.{$other->id}", 'Use a brighter picture')->call('sendBack', $other->id);
        $this->assertSame(['draft', 'HQ: Use a brighter picture'], [$other->fresh()->status, $other->fresh()->skip_reason]);
        Livewire::actingAs($owner)->test(SocialPosts::class)->assertSee('HQ: Use a brighter picture');
    }

    public function test_partners_get_share_your_business_with_their_live_pages_only(): void
    {
        $owner = \App\Models\User::find($this->w['store']->provider->user_id);
        $hidden = $this->makeStore($this->w['franchise'], $this->w['zone'], $this->w['store']->provider, ['name' => 'Closed Shop', 'is_active' => false]);

        $cards = PartnerBusinesses::for($owner);
        $this->assertCount(1, $cards);
        $this->assertSame('Lakshmi Stores', $cards[0]['name']);
        $this->assertStringContainsString('/nellore/shop/'.$this->w['store']->slug.'?utm_source=partner', $cards[0]['url']);
        $this->assertStringContainsString($cards[0]['url'], $cards[0]['text']);
        $this->assertStringNotContainsString($hidden->name, json_encode($cards));
        $this->assertSame([], PartnerBusinesses::for($this->makeCustomer()));

        Livewire::actingAs($owner)->test(SellerDashboard::class)->assertSee('Share your business')->assertSee('data-share-my-business', false);
    }
}

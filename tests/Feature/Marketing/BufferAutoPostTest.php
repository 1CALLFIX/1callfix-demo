<?php

namespace Tests\Feature\Marketing;

use App\Livewire\Marketing\BufferConnect;
use App\Livewire\Marketing\PostingRoutes as PostingRoutesCard;
use App\Livewire\Marketing\SocialPosts;
use App\Models\Setting;
use App\Models\SocialPost;
use App\Models\SocialPostDelivery;
use App\Services\Marketing\AutoPoster;
use App\Services\Marketing\BufferApi;
use App\Services\Marketing\MetaGraph;
use App\Services\Marketing\PostingRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Posting through Buffer (owner 2026-11-22: "on buffer they will get autoposted … we will on/off direct or buffer from
 * admin"): the Super Admin pastes the Buffer API key once, each place is set to Direct / Buffer / By hand, and posts
 * go out by themselves once per place, then are confirmed with Buffer.
 */
class BufferAutoPostTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private const KEY = 'buf_live_0123456789abcdefghijklmnop';

    /** @var array<string, mixed> GraphQL answers keyed by a word in the query; changed between calls. */
    private array $answers = [];

    /** @var list<array> every createPost input Buffer received */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->answers = [
            'organizations' => ['account' => ['organizations' => [['id' => 'org1', 'name' => '1CallFix']]]],
            'channels' => ['channels' => [
                ['id' => 'ch-gbp', 'name' => '1CallFix Nellore', 'displayName' => null, 'service' => 'googlebusiness', 'isDisconnected' => false, 'isLocked' => false],
                ['id' => 'ch-fb', 'name' => '1Callfix Solutions', 'displayName' => null, 'service' => 'facebook', 'isDisconnected' => false, 'isLocked' => false],
                ['id' => 'ch-ig', 'name' => '1callfixsolutions', 'displayName' => null, 'service' => 'instagram', 'isDisconnected' => false, 'isLocked' => false],
                ['id' => 'ch-li', 'name' => '1CallFix', 'displayName' => null, 'service' => 'linkedin', 'isDisconnected' => false, 'isLocked' => false],
                ['id' => 'ch-pin', 'name' => 'Pins', 'displayName' => null, 'service' => 'pinterest', 'isDisconnected' => false, 'isLocked' => false],
            ]],
            '__type' => [
                'ShareMode' => ['enumValues' => [['name' => 'addToQueue'], ['name' => 'shareNext'], ['name' => 'shareNow'], ['name' => 'customScheduled']]],
                'SchedulingType' => ['enumValues' => [['name' => 'notification'], ['name' => 'automatic']]],
                'PostType' => ['enumValues' => [['name' => 'post'], ['name' => 'story'], ['name' => 'reel']]],
                'PostTypeFacebook' => ['enumValues' => [['name' => 'post'], ['name' => 'reel'], ['name' => 'story']]],
                'PostTypeGoogleBusiness' => ['enumValues' => [['name' => 'event'], ['name' => 'offer'], ['name' => 'whats_new']]],
                'GoogleBusinessPostActionType' => ['enumValues' => [['name' => 'book'], ['name' => 'learn_more'], ['name' => 'none']]],
            ],
            'createPost' => fn () => ['createPost' => ['post' => ['id' => 'bp'.count($this->created), 'status' => 'sending', 'externalLink' => null]]],
            'post(input' => ['post' => ['id' => 'bp1', 'status' => 'sent', 'externalLink' => 'https://posts.gle/abc', 'error' => null]],
        ];
        Http::fake(function (Request $request) {
            if (! str_starts_with($request->url(), BufferApi::ENDPOINT)) {
                return Http::response(['error' => ['message' => 'unexpected '.$request->url()]], 400);
            }
            if ($request->header('Authorization')[0] !== 'Bearer '.self::KEY) {
                return Http::response(['message' => 'Unauthorized'], 401);
            }
            $query = (string) $request['query'];
            if (str_contains($query, 'createPost')) {
                $this->created[] = $request['variables']['input'];
            }
            foreach ($this->answers as $needle => $answer) {
                if (str_contains($query, $needle)) {
                    if ($answer instanceof \Illuminate\Http\Client\Response || $answer instanceof \GuzzleHttp\Promise\PromiseInterface) {
                        return $answer;
                    }

                    return Http::response(['data' => is_callable($answer) ? $answer() : $answer]);
                }
            }

            return Http::response(['errors' => [['message' => 'unexpected query']]]);
        });
    }

    private function connectBuffer(): void
    {
        app(BufferApi::class)->connect(self::KEY);
    }

    private function duePost(array $over = []): SocialPost
    {
        Storage::disk('public')->put('marketing/banner.png', $this->png(1200, 400));

        return SocialPost::create(array_merge([
            'title' => 'AC offer', 'caption_en' => 'AC service this week', 'hashtags' => '#Nellore',
            'platforms' => ['google_business', 'whatsapp'], 'status' => 'scheduled', 'scheduled_for' => now()->subMinutes(5),
            'source' => 'manual', 'link_url' => '/nellore/services', 'image_path' => 'marketing/banner.png',
        ], $over));
    }

    private function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 200));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    public function test_super_admin_connects_buffer_the_key_never_shows_and_channels_are_matched(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(BufferConnect::class)
            ->set('apiKey', 'short')->call('connect')->assertHasErrors('apiKey')
            ->set('apiKey', self::KEY)->call('connect')->assertHasNoErrors()->assertSet('apiKey', '')
            ->assertSee('Connected to Buffer')->assertSee('1CallFix Nellore')->assertSee('Pins (pinterest)')
            ->assertDontSee(self::KEY);

        $buffer = app(BufferApi::class);
        $this->assertTrue($buffer->connected());
        $this->assertStringNotContainsString(self::KEY, (string) Setting::get(BufferApi::API_KEY), 'stored encrypted');
        $this->assertSame(self::KEY, $buffer->apiKey());
        $this->assertEquals(['google_business' => 'ch-gbp', 'facebook' => 'ch-fb', 'instagram' => 'ch-ig', 'linkedin' => 'ch-li'], $buffer->map(),
            'each place gets its channel; Pinterest is not one of ours');
        $this->assertSame('whats_new', $buffer->enum('PostTypeGoogleBusiness', ['what'], 'whatsNew'), 'enum values come from Buffer itself');
    }

    public function test_the_social_posts_page_shows_both_cards_to_the_super_admin(): void
    {
        $this->actingAs($this->makeSuperAdmin())->get(route('admin.marketing.posts'))->assertOk()
            ->assertSee('How each place posts')->assertSee('Post through Buffer')->assertSee('Direct: our own Facebook');
    }

    public function test_a_wrong_key_saves_nothing(): void
    {
        Livewire::actingAs($this->makeSuperAdmin())->test(BufferConnect::class)
            ->set('apiKey', 'buf_wrong_key_0123456789abcdefghij')->call('connect')
            ->assertSee('Not connected: Buffer did not accept the API key');

        $this->assertNull(app(BufferApi::class)->apiKey());
        $this->assertFalse(app(BufferApi::class)->connected());
    }

    public function test_only_the_super_admin_sees_the_buffer_and_routes_cards(): void
    {
        $staff = $this->makeUserWithPermission('marketing.posts.manage', 'global');

        Livewire::actingAs($staff)->test(BufferConnect::class)->assertForbidden();
        Livewire::actingAs($staff)->test(PostingRoutesCard::class)->assertForbidden();
        Livewire::actingAs($staff)->test(SocialPosts::class)->assertOk()->assertDontSee('data-buffer-connect', false)->assertDontSee('data-posting-routes', false);
    }

    public function test_google_business_goes_out_once_through_buffer_and_is_confirmed(): void
    {
        $this->connectBuffer();
        Livewire::actingAs($this->makeSuperAdmin())->test(PostingRoutesCard::class)
            ->call('setRoute', 'google_business', 'buffer')->assertSee('Google Business now posts by itself through Buffer')
            ->assertSee('Posts by itself');
        $post = $this->duePost();

        $this->artisan('marketing:posts-due')->assertSuccessful();

        $this->assertCount(1, $this->created);
        $input = $this->created[0];
        $this->assertSame('ch-gbp', $input['channelId']);
        $this->assertSame(['automatic', 'shareNow'], [$input['schedulingType'], $input['mode']]);
        $this->assertSame('whats_new', $input['metadata']['google']['type']);
        $this->assertSame('learn_more', $input['metadata']['google']['detailsWhatsNew']['button']);
        $this->assertStringContainsString('utm_source=google_business', $input['metadata']['google']['detailsWhatsNew']['link']);
        $this->assertStringNotContainsString('http', $input['text'], 'Google Business carries the link on its button');
        $this->assertStringStartsWith('http', $input['assets'][0]['image']['url']);

        // The schedule ran createPost and then asked Buffer, which says the post is live.
        $delivery = SocialPostDelivery::where('social_post_id', $post->id)->where('platform', 'google_business')->sole();
        $this->assertSame(['posted', 'buffer', 'bp1', 'https://posts.gle/abc'], [$delivery->status, $delivery->via, $delivery->external_id, $delivery->post_url]);
        $this->assertSame('scheduled', $post->fresh()->status, 'WhatsApp still waits for its tap');

        $this->artisan('marketing:posts-due')->assertSuccessful();
        $this->assertCount(1, $this->created, 'never posted twice');

        Livewire::actingAs($this->makeSuperAdmin())->test(SocialPosts::class)
            ->assertSee('via Buffer')
            ->call('undoDone', $post->id, 'google_business')->assertSee('This went out by itself');
        $this->assertSame('posted', $delivery->fresh()->status);
    }

    public function test_a_post_buffer_is_still_sending_shows_as_sent_and_hides_the_share_button(): void
    {
        $this->connectBuffer();
        Setting::set(PostingRoutes::PREFIX.'google_business', 'buffer');
        $this->answers['post(input'] = ['post' => ['id' => 'bp1', 'status' => 'sending', 'externalLink' => null, 'error' => null]];
        $post = $this->duePost(['platforms' => ['google_business']]);

        $this->artisan('marketing:posts-due')->assertSuccessful();

        $this->assertSame('sent', SocialPostDelivery::where('social_post_id', $post->id)->value('status'));
        Livewire::actingAs($this->makeSuperAdmin())->test(SocialPosts::class)
            ->assertSee('Sent to Buffer')->assertDontSee('Share on Google Business');
    }

    public function test_a_buffer_failure_waits_for_try_again(): void
    {
        $this->connectBuffer();
        Setting::set(PostingRoutes::PREFIX.'google_business', 'buffer');
        $this->answers['post(input'] = ['post' => ['id' => 'bp1', 'status' => 'error', 'externalLink' => null, 'error' => ['message' => 'Location not verified']]];
        $post = $this->duePost(['platforms' => ['google_business']]);

        $this->artisan('marketing:posts-due')->assertSuccessful();
        $delivery = SocialPostDelivery::where('social_post_id', $post->id)->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('Location not verified', $delivery->error);

        $this->artisan('marketing:posts-due')->assertSuccessful();
        $this->assertCount(1, $this->created, 'a failure is never retried by itself');

        $this->answers['post(input'] = ['post' => ['id' => 'bp1', 'status' => 'sent', 'externalLink' => 'https://posts.gle/ok', 'error' => null]];
        Livewire::actingAs($this->makeSuperAdmin())->test(SocialPosts::class)
            ->assertSee('Could not post on Google Business')->assertSee('Try again')
            ->call('postNow', $post->id, 'google_business');
        $this->assertCount(2, $this->created);
        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_createpost_refused_by_buffer_is_a_failure_with_its_reason(): void
    {
        $this->connectBuffer();
        Setting::set(PostingRoutes::PREFIX.'google_business', 'buffer');
        $this->answers['createPost'] = ['createPost' => ['message' => 'Text is too long for Google Business']];
        $post = $this->duePost(['platforms' => ['google_business']]);

        $this->assertSame(['posted' => 0, 'failed' => 1], app(AutoPoster::class)->publishDue());
        $this->assertStringContainsString('Text is too long', (string) SocialPostDelivery::where('social_post_id', $post->id)->value('error'));
    }

    public function test_facebook_and_instagram_can_switch_from_direct_to_buffer_and_never_go_both_ways(): void
    {
        // Our own Facebook connection is there too.
        Setting::set(MetaGraph::APP_ID, '445952729385294');
        Setting::set(MetaGraph::APP_SECRET, Crypt::encryptString('0123456789abcdef0123456789abcdef'));
        app(MetaGraph::class)->savePage(['id' => '111', 'name' => '1Callfix Solutions', 'token' => 'PAGE-TOKEN', 'ig_id' => '222', 'ig_username' => 'x']);
        $this->connectBuffer();
        $routes = app(PostingRoutes::class);
        $this->assertSame(['direct', 'direct'], [$routes->route('facebook'), $routes->route('instagram')], 'unchanged until the Super Admin chooses');

        $card = Livewire::actingAs($this->makeSuperAdmin())->test(PostingRoutesCard::class);
        $card->call('setRoute', 'facebook', 'buffer')->call('setRoute', 'instagram', 'buffer');
        $this->duePost(['platforms' => ['facebook', 'instagram']]);

        app(AutoPoster::class)->publishDue();

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'));
        $this->assertSame(['ch-fb', 'ch-ig'], array_column($this->created, 'channelId'));
        $this->assertSame('post', $this->created[0]['metadata']['facebook']['type']);
        $this->assertSame(['type' => 'post', 'shouldShareToFeed' => true], $this->created[1]['metadata']['instagram']);
        $this->assertStringContainsString('/marketing/instagram/', $this->created[1]['assets'][0]['image']['url'], 'Instagram gets the padded JPEG');
        $this->assertStringContainsString('utm_source=facebook', $this->created[0]['text']);

        $card->call('setRoute', 'facebook', 'manual');
        $this->assertNotContains('facebook', $routes->autoPlatforms());
    }

    public function test_whatsapp_is_only_by_hand_and_buffer_only_places_appear_once_ready(): void
    {
        $admin = $this->makeSuperAdmin();
        Livewire::actingAs($admin)->test(PostingRoutesCard::class)
            ->call('setRoute', 'whatsapp', 'buffer')->assertStatus(422);
        Livewire::actingAs($admin)->test(PostingRoutesCard::class)
            ->call('setRoute', 'google_business', 'direct')->assertStatus(422);

        $routes = app(PostingRoutes::class);
        $this->assertNotContains('linkedin', $routes->editorPlatforms());
        $this->connectBuffer();
        Setting::set(PostingRoutes::PREFIX.'linkedin', 'buffer');
        $this->assertContains('linkedin', $routes->editorPlatforms());

        Livewire::actingAs($admin)->test(PostingRoutesCard::class)->assertSee('LinkedIn');
    }

    public function test_an_unusable_key_stops_buffer_posting(): void
    {
        $this->connectBuffer();
        Setting::set(PostingRoutes::PREFIX.'google_business', 'buffer');
        Setting::set(BufferApi::API_KEY, Crypt::encryptString('buf_revoked_0123456789abcdefghijkl'));
        $post = $this->duePost(['platforms' => ['google_business']]);

        app(AutoPoster::class)->publishDue();

        $this->assertStringContainsString('did not accept the API key', (string) SocialPostDelivery::where('social_post_id', $post->id)->value('error'));
        $this->assertFalse(app(BufferApi::class)->connected());
        $this->assertNotContains('google_business', app(PostingRoutes::class)->autoPlatforms());
        $this->assertStringContainsString('Connect Buffer below', (string) app(PostingRoutes::class)->missing('google_business'));
    }

    public function test_x_text_is_cut_to_fit_and_keeps_the_link_whole(): void
    {
        $post = new SocialPost(['caption_en' => str_repeat('Fast AC repair in Nellore. ', 30), 'link_url' => '/nellore/services']);
        $post->id = 5;
        $text = app(BufferApi::class)->text($post, 'x');

        $this->assertStringEndsWith('utm_campaign=post-5', $text);
        $words = trim(explode("\n\n", $text)[0]);
        $this->assertLessThanOrEqual(280 - 23 - 2, mb_strlen($words));
    }
}

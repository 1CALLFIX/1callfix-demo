<?php

namespace Tests\Feature\Marketing;

use App\Livewire\Marketing\MetaConnect;
use App\Livewire\Marketing\SocialPosts;
use App\Models\Setting;
use App\Models\SocialMediaLink;
use App\Models\SocialPost;
use App\Models\SocialPostDelivery;
use App\Services\Marketing\AutoPoster;
use App\Services\Marketing\MetaGraph;
use App\Services\Marketing\SocialPostPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/**
 * Automatic Facebook / Instagram posting (owner 2026-11-22): the Super Admin connects the company's own Meta app once;
 * scheduled posts then go out by themselves at their time, once per platform, never retried by themselves.
 */
class MetaAutoPostTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private const SECRET = '0123456789abcdef0123456789abcdef';

    private function connect(bool $instagram = true): void
    {
        Setting::set(MetaGraph::APP_ID, '445952729385294');
        Setting::set(MetaGraph::APP_SECRET, Crypt::encryptString(self::SECRET));
        app(MetaGraph::class)->savePage(['id' => '111', 'name' => '1Callfix Solutions', 'token' => 'PAGE-TOKEN',
            'ig_id' => $instagram ? '222' : null, 'ig_username' => $instagram ? 'onecallfix' : null]);
    }

    private function duePost(array $over = []): SocialPost
    {
        Storage::disk('public')->put('marketing/banner.png', $this->png(1200, 400));

        return SocialPost::create(array_merge([
            'title' => 'AC offer', 'caption_en' => 'AC service this week', 'platforms' => ['facebook', 'instagram', 'whatsapp'],
            'status' => 'scheduled', 'scheduled_for' => now()->subMinutes(5), 'source' => 'manual',
            'link_url' => '/nellore/services', 'image_path' => 'marketing/banner.png',
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

    /** @var array<string, mixed> answers that win over the normal ones; change it between calls (Http::fake keeps its first callback). */
    private array $overrides = [];

    private function fakeGraph(array $overrides = []): void
    {
        $this->overrides = $overrides;
        Http::fake(function (Request $request) {
            foreach ($this->overrides as $needle => $response) {
                if (str_contains($request->url(), $needle)) {
                    return $response;
                }
            }
            $url = $request->url();

            return match (true) {
                str_contains($url, '/111/photos') => Http::response(['id' => 'p1', 'post_id' => '111_999']),
                str_contains($url, '/222/media_publish') => Http::response(['id' => 'm77']),
                str_contains($url, '/222/media') => Http::response(['id' => 'c55']),
                str_contains($url, '/m77') => Http::response(['permalink' => 'https://www.instagram.com/p/abc/']),
                default => Http::response(['error' => ['message' => 'unexpected '.$url]], 400),
            };
        });
    }

    public function test_super_admin_saves_the_app_and_connects_once_the_secret_never_shows(): void
    {
        $admin = $this->makeSuperAdmin();

        Livewire::actingAs($admin)->test(MetaConnect::class)
            ->set('appId', '445952729385294')->set('appSecret', 'not-a-secret')->call('saveApp')->assertHasErrors('appSecret')
            ->set('appSecret', self::SECRET)->call('saveApp')->assertHasNoErrors()->assertSet('appSecret', '')
            ->assertDontSee(self::SECRET);
        $this->assertStringNotContainsString(self::SECRET, (string) Setting::get(MetaGraph::APP_SECRET));
        $this->assertSame(self::SECRET, app(MetaGraph::class)->appSecret());

        $redirect = $this->actingAs($admin)->get(route('admin.marketing.meta.connect'));
        $redirect->assertRedirect();
        $location = $redirect->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/v27.0/dialog/oauth?', $location);
        $this->assertStringContainsString('client_id=445952729385294', $location);
        $this->assertStringContainsString('instagram_content_publish', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        Http::fake([
            'graph.facebook.com/v27.0/oauth/access_token*' => Http::sequence()->push(['access_token' => 'SHORT'])->push(['access_token' => 'LONG']),
            'graph.facebook.com/v27.0/me/accounts*' => Http::response(['data' => [[
                'id' => '111', 'name' => '1Callfix Solutions', 'access_token' => 'PAGE-TOKEN',
                'instagram_business_account' => ['id' => '222', 'username' => 'onecallfix'],
            ]]]),
        ]);

        $this->actingAs($admin)->get(route('admin.marketing.meta.callback', ['code' => 'CODE', 'state' => 'wrong']))
            ->assertRedirect(route('admin.marketing.posts'));
        $this->assertFalse(app(MetaGraph::class)->connected(), 'a state that does not match connects nothing');

        $this->actingAs($admin)->withSession(['meta_oauth_state' => $q['state']])
            ->get(route('admin.marketing.meta.callback', ['code' => 'CODE', 'state' => $q['state']]))
            ->assertRedirect(route('admin.marketing.posts'))->assertSessionHas('meta_message', 'Connected: 1Callfix Solutions and Instagram @onecallfix.');

        $meta = app(MetaGraph::class);
        $this->assertTrue($meta->connected());
        $this->assertSame(['facebook', 'instagram'], $meta->autoPlatforms());
        $raw = (string) DB::table('social_media_links')->where('platform', 'facebook')->value('access_token');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('PAGE-TOKEN', $raw, 'the Page token is stored encrypted');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fb_exchange_token=SHORT'));
    }

    public function test_only_the_super_admin_can_connect(): void
    {
        $staff = $this->makeUserWithPermission('marketing.posts.manage', 'global');

        $this->actingAs($staff)->get(route('admin.marketing.meta.connect'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.marketing.meta.callback', ['code' => 'x', 'state' => 'y']))->assertForbidden();
        Livewire::actingAs($staff)->test(MetaConnect::class)->assertForbidden();
        Livewire::actingAs($staff)->test(SocialPosts::class)->assertOk()->assertDontSee('data-meta-connect', false);
    }

    public function test_due_posts_go_out_once_on_facebook_and_instagram_and_whatsapp_stays_one_tap(): void
    {
        Storage::fake('public');
        $this->connect();
        $post = $this->duePost();
        $later = $this->duePost(['title' => 'Later', 'scheduled_for' => now()->addHour()]);
        $this->fakeGraph();

        $this->artisan('marketing:posts-due')->assertSuccessful();

        $post->refresh()->load('deliveries');
        $this->assertTrue($post->isPostedOn('facebook'));
        $this->assertTrue($post->isPostedOn('instagram'));
        $this->assertFalse($post->isPostedOn('whatsapp'));
        $this->assertSame('scheduled', $post->status, 'WhatsApp still waits for its tap');
        $this->assertSame('https://www.facebook.com/111_999', $post->deliveries->firstWhere('platform', 'facebook')->post_url);
        $this->assertSame('https://www.instagram.com/p/abc/', $post->deliveries->firstWhere('platform', 'instagram')->post_url);
        $this->assertSame(0, $later->deliveries()->count(), 'a post whose time has not come waits');

        // The photo post carries the caption with the tracked link; requests are signed with appsecret_proof.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/111/photos')
            && str_contains((string) $r['caption'], 'utm_source=facebook') && $r['appsecret_proof'] === hash_hmac('sha256', 'PAGE-TOKEN', self::SECRET));

        // Instagram got a JPEG padded from 3:1 to 1.91:1, never cropped.
        $files = Storage::disk('public')->files('marketing/instagram');
        $this->assertCount(1, $files);
        [$w, $h, $type] = getimagesizefromstring(Storage::disk('public')->get($files[0]));
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame(1200, $w);
        $this->assertSame((int) ceil(1200 / 1.91), $h);

        $this->artisan('marketing:posts-due')->assertSuccessful();
        Http::assertSentCount(4);   // photos, media, media_publish, permalink: nothing twice
    }

    public function test_a_failure_waits_for_try_again_and_an_expired_login_asks_to_connect_again(): void
    {
        Storage::fake('public');
        $this->connect(false);
        $post = $this->duePost(['platforms' => ['facebook']]);
        $this->fakeGraph(['/111/photos' => Http::response(['error' => ['message' => 'Image too small', 'code' => 100]], 400)]);

        $this->assertSame(['posted' => 0, 'failed' => 1], app(AutoPoster::class)->publishDue());
        $delivery = SocialPostDelivery::where('social_post_id', $post->id)->first();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('Image too small', $delivery->error);
        $this->assertSame(['posted' => 0, 'failed' => 0], app(AutoPoster::class)->publishDue(), 'never retried by itself');

        $this->overrides = [];
        Livewire::actingAs($this->makeSuperAdmin())->test(SocialPosts::class)
            ->assertSee('Could not post on Facebook')->assertSee('Try again')
            ->call('postNow', $post->id, 'facebook');
        $this->assertSame('posted', $delivery->fresh()->status);
        $this->assertSame('posted', $post->fresh()->status);

        $other = $this->duePost(['platforms' => ['facebook'], 'title' => 'Other']);
        $this->overrides = ['/111/photos' => Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 400)];
        app(AutoPoster::class)->publishDue();
        $this->assertStringContainsString('Connect again', (string) SocialPostDelivery::where('social_post_id', $other->id)->value('error'));
        $this->assertFalse(app(MetaGraph::class)->connected());
        $this->assertSame([], app(MetaGraph::class)->autoPlatforms());
    }

    public function test_nothing_posts_by_itself_when_not_connected_or_switched_off(): void
    {
        Storage::fake('public');
        Http::fake();
        $post = $this->duePost();

        app(AutoPoster::class)->publishDue();
        $this->connect();
        Setting::set(MetaGraph::AUTOPOST, '0');
        app(AutoPoster::class)->publishDue();

        Http::assertNothingSent();
        $this->assertSame(0, $post->deliveries()->count());
        $this->assertNotNull(SocialMediaLink::where('platform', 'facebook')->value('access_token'));
    }

    public function test_the_reminder_does_not_count_what_posts_by_itself(): void
    {
        Storage::fake('public');
        $this->connect();
        $this->duePost(['platforms' => ['facebook', 'instagram'], 'scheduled_for' => CarbonImmutable::now(SocialPostPlanner::zone())->endOfDay()->subMinute()->utc()]);
        Http::fake();

        $this->artisan('marketing:posts-due')->expectsOutputToContain('0 still to share today')->assertSuccessful();
    }
}

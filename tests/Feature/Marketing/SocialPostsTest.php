<?php

namespace Tests\Feature\Marketing;

use App\Livewire\Auth\Login as AdminLogin;
use App\Livewire\Marketing\SocialPosts;
use App\Models\Banner;
use App\Models\Coupon;
use App\Models\JobOpening;
use App\Models\Setting;
use App\Models\SocialPost;
use App\Models\User;
use App\Notifications\MarketingPostsReady;
use App\Services\Marketing\ModuleGate;
use App\Services\Marketing\PostIdeas;
use App\Services\Marketing\SocialPostPlanner;
use App\Services\ModuleActivationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/** Digital Marketing, social posts (owner 2026-11-21): plan, auto-skip switched-off modules, one-tap share, reminders. */
class SocialPostsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RbacTestHelpers;
    use RefreshDatabase;

    private function makePost(array $over = []): SocialPost
    {
        return SocialPost::create(array_merge([
            'title' => 'AC offer', 'caption_en' => 'AC service this week', 'platforms' => ['facebook', 'instagram'],
            'status' => 'scheduled', 'scheduled_for' => CarbonImmutable::now(SocialPostPlanner::zone())->setTime(12, 0)->utc(),
            'source' => 'manual', 'link_url' => '/nellore/services',
        ], $over));
    }

    private function banner(string $title, ?string $module): Banner
    {
        return Banner::create(['title' => $title, 'image' => 'banners/'.Str::random(6).'.jpg', 'module' => $module, 'placement' => 'home_top', 'is_active' => true, 'link' => '/x', 'sort_order' => 1]);
    }

    public function test_screen_needs_the_permission_and_the_marketing_role_has_it(): void
    {
        Livewire::actingAs($this->makeUserWithNoPermissions())->test(SocialPosts::class)->assertForbidden();
        Livewire::actingAs($this->makeUserWithPermission('marketing.posts.manage', 'global'))->test(SocialPosts::class)->assertOk()->assertSee('Social posts');
    }

    public function test_a_module_switched_off_in_the_city_skips_its_posts_and_switched_on_in_one_area_counts(): void
    {
        [, $city, $franchise, $zone] = $this->makeFranchiseTree();
        $gate = app(ModuleGate::class);

        $this->assertNull($gate->offReason('service', $city->id), 'services are on by default');
        $this->assertSame("Food is switched off in {$city->name}", $gate->offReason('food', $city->id));
        $this->assertSame('Food is switched off everywhere', $gate->offReason('food'));

        app(ModuleActivationService::class)->setActive('food', 'zone', $zone->id, true);
        $this->assertNull(app(ModuleGate::class)->offReason('food', $city->id), 'on in one zone of the city is enough');
    }

    public function test_the_daily_check_skips_upcoming_posts_for_a_switched_off_module(): void
    {
        [, $city] = $this->makeFranchiseTree();
        $food = $this->makePost(['module' => 'food', 'city_id' => $city->id]);
        $service = $this->makePost(['module' => 'service', 'city_id' => $city->id]);

        $this->artisan('marketing:posts-due')->assertSuccessful();

        $this->assertSame(['skipped', "Food is switched off in {$city->name}"], [$food->fresh()->status, $food->fresh()->skip_reason]);
        $this->assertSame('scheduled', $service->fresh()->status);
    }

    public function test_ideas_come_from_live_data_leave_out_off_modules_and_offers_say_online_payment(): void
    {
        $this->makeFranchiseTree();
        $this->banner('AC service', 'service');
        $this->banner('Biryani week', 'food');
        Setting::set('coupons.enabled', '1');
        Coupon::create(['code' => 'WELCOME', 'name' => '₹150 off your first booking', 'status' => 'active', 'is_active' => true, 'discount_type' => 'fixed', 'value' => 150, 'min_order_value' => 1000]);
        JobOpening::create(['title' => 'Field Executive', 'slug' => 'field-executive', 'is_open' => true, 'openings' => 2, 'sort_order' => 1]);

        $ideas = collect(app(PostIdeas::class)->all());

        $this->assertNotNull($ideas->firstWhere('title', 'AC service'));
        $this->assertNull($ideas->firstWhere('title', 'Biryani week'), 'Food is off: no ad for it');
        $offer = $ideas->firstWhere('source', 'offer');
        $this->assertStringContainsString('WELCOME', $offer['caption_en']);
        $this->assertStringContainsString('pay online', $offer['caption_en']);
        $this->assertSame('/careers/field-executive', $ideas->firstWhere('title', 'Hiring: Field Executive')['link_url']);
        $this->assertNotNull($ideas->firstWhere('source', 'app'));
    }

    public function test_using_an_idea_makes_a_draft_and_it_is_not_offered_again_for_two_weeks(): void
    {
        $this->makeFranchiseTree();
        $this->banner('AC service', 'service');
        $admin = $this->makeSuperAdmin();
        $index = collect(app(PostIdeas::class)->all())->search(fn ($i) => $i['title'] === 'AC service');

        Livewire::actingAs($admin)->test(SocialPosts::class)->call('useIdea', $index)->assertSet('editing', true);

        $post = SocialPost::sole();
        $this->assertSame(['draft', 'banner', 'service'], [$post->status, $post->source, $post->module]);
        $this->assertNull(collect(app(PostIdeas::class)->all())->firstWhere('title', 'AC service'));
    }

    public function test_fill_my_next_7_days_makes_one_draft_a_day_only_on_free_days_and_never_schedules(): void
    {
        $this->makeFranchiseTree();
        foreach (range(1, 9) as $i) {
            $this->banner("Banner {$i}", 'service');
        }
        $tomorrow = CarbonImmutable::now(SocialPostPlanner::zone())->addDay()->startOfDay();
        $this->makePost(['scheduled_for' => SocialPostPlanner::at($tomorrow)]);

        Livewire::actingAs($this->makeSuperAdmin())->test(SocialPosts::class)->call('fillWeek')->assertSee('6 draft posts added');

        $this->assertSame(6, SocialPost::where('status', 'draft')->count());
        $this->assertSame(7, SocialPost::whereNotNull('scheduled_for')->get()->map(fn ($p) => $p->scheduled_for->setTimezone(SocialPostPlanner::zone())->toDateString())->unique()->count());
    }

    public function test_scheduling_a_post_and_refusing_one_for_a_switched_off_module(): void
    {
        [, $city] = $this->makeFranchiseTree();
        $day = CarbonImmutable::now(SocialPostPlanner::zone())->addDays(2)->toDateString();

        Livewire::actingAs($this->makeSuperAdmin())->test(SocialPosts::class)
            ->call('newPost')
            ->set('title', 'Food fest')->set('captionEn', 'Order now')->set('module', 'food')->set('cityId', $city->id)
            ->set('day', $day)->set('time', '10:00')
            ->call('save', true)->assertHasErrors('module')
            ->set('module', 'service')->call('save', true)->assertHasNoErrors()->assertSee('Scheduled for');

        $post = SocialPost::sole();
        $this->assertSame('scheduled', $post->status);
        $this->assertSame($day.' 10:00', $post->scheduled_for->setTimezone(SocialPostPlanner::zone())->format('Y-m-d H:i'));
    }

    public function test_done_on_every_platform_marks_the_post_posted_and_undo_brings_it_back(): void
    {
        $post = $this->makePost();
        $screen = Livewire::actingAs($this->makeSuperAdmin())->test(SocialPosts::class)
            ->assertSee('Share on Facebook')
            ->set('postUrls.'.$post->id.'-facebook', 'https://facebook.com/1callfix/posts/1')
            ->call('markDone', $post->id, 'facebook');
        $this->assertSame('scheduled', $post->fresh()->status);
        $this->assertSame('https://facebook.com/1callfix/posts/1', $post->deliveries()->where('platform', 'facebook')->value('post_url'));

        $screen->call('markDone', $post->id, 'instagram');
        $this->assertSame('posted', $post->fresh()->status);

        $screen->call('undoDone', $post->id, 'instagram');
        $this->assertSame('scheduled', $post->fresh()->status);
    }

    public function test_each_platform_gets_its_own_tracked_link_and_share_text(): void
    {
        $post = $this->makePost(['caption_te' => 'ఏసీ సర్వీస్', 'hashtags' => '#1CallFix']);

        $this->assertSame(url('/nellore/services').'?utm_source=instagram&utm_medium=social&utm_campaign=post-'.$post->id, $post->trackedLink('instagram'));
        $text = $post->shareText('facebook');
        $this->assertStringContainsString("AC service this week\n\nఏసీ సర్వీస్\n\n#1CallFix\n\n", $text);
        $this->assertStringContainsString('utm_source=facebook', $text);
    }

    public function test_the_team_is_reminded_once_in_the_morning_and_nudged_once_in_the_evening(): void
    {
        Notification::fake();
        $team = $this->makeUserWithPermission('marketing.posts.manage', 'global');
        $team->forceFill(['email' => 'team@example.test'])->save();
        $other = $this->makeUserWithPermission('bookings.view', 'global');
        $other->forceFill(['email' => 'other@example.test'])->save();
        $this->makePost();

        $this->travelTo(CarbonImmutable::now(SocialPostPlanner::zone())->setTime(9, 0));
        $this->artisan('marketing:posts-due');
        Notification::assertNothingSent();

        $this->travelTo(CarbonImmutable::now(SocialPostPlanner::zone())->setTime(9, 45));
        $this->artisan('marketing:posts-due');
        $this->artisan('marketing:posts-due');
        Notification::assertSentToTimes($team, MarketingPostsReady::class, 1);
        Notification::assertNotSentTo($other, MarketingPostsReady::class);

        $this->travelTo(CarbonImmutable::now(SocialPostPlanner::zone())->setTime(18, 15));
        $this->artisan('marketing:posts-due');
        Notification::assertSentTo($team, MarketingPostsReady::class, fn ($n) => $n->evening && $n->count === 1);
        Notification::assertSentToTimes($team, MarketingPostsReady::class, 2);
    }

    public function test_a_city_team_sees_only_its_city_and_cannot_plan_for_every_city(): void
    {
        [, $city, $franchise] = $this->makeFranchiseTree();
        $otherCity = $this->makeCity();
        $mine = $this->makePost(['title' => 'Mine', 'city_id' => $city->id, 'country_id' => $city->country_id, 'franchise_id' => $franchise->id]);
        $theirs = $this->makePost(['title' => 'Theirs', 'city_id' => $otherCity->id, 'country_id' => $otherCity->country_id]);
        $team = $this->makeUserWithPermission('marketing.posts.manage', 'city', $city->id);

        $screen = Livewire::actingAs($team)->test(SocialPosts::class)->assertSee('Mine')->assertDontSee('Theirs');
        $screen->call('newPost')->set('cityId', null)->set('title', 'All')->set('captionEn', 'x')->call('save', false)->assertForbidden();

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($team)->test(SocialPosts::class)->call('markDone', $theirs->id, 'facebook');
    }

    public function test_only_super_admin_changes_the_settings(): void
    {
        Livewire::actingAs($this->makeUserWithPermission('marketing.posts.manage', 'global'))->test(SocialPosts::class)
            ->assertDontSee('Remind the team at')->call('setTime', 'reminder', '08:00')->assertForbidden();

        Livewire::actingAs($this->makeSuperAdmin())->test(SocialPosts::class)
            ->call('setTime', 'reminder', '08:00')->call('toggleDefaultPlatform', 'whatsapp');

        $this->assertSame('08:00', SocialPostPlanner::reminderTime());
        $this->assertNotContains('whatsapp', SocialPostPlanner::platforms());
    }

    public function test_the_marketing_test_login_signs_in_opens_both_screens_and_is_switched_off_with_the_rest(): void
    {
        [, , $franchise] = $this->makeFranchiseTree();
        $this->artisan('test-accounts', ['action' => 'create', '--password' => 'test-pass-123', '--franchise' => $franchise->id])->assertSuccessful();
        $user = User::where('email', 'test.marketing@1callfix.test')->sole();

        $this->assertTrue($user->hasPermissionAnywhere('marketing.posts.manage'));
        $this->assertTrue($user->hasPermissionAnywhere('marketing.contacts.manage'));
        Livewire::test(AdminLogin::class)->set('email', 'test.marketing@1callfix.test')->set('password', 'test-pass-123')->call('submit')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($user)->get(route('admin.marketing.posts'))->assertOk();
        $this->actingAs($user)->get(route('admin.marketing.contacts'))->assertOk();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();

        auth()->logout();
        $this->artisan('test-accounts', ['action' => 'disable'])->assertSuccessful();
        Livewire::test(AdminLogin::class)->set('email', 'test.marketing@1callfix.test')->set('password', 'test-pass-123')->call('submit')->assertNoRedirect();
    }
}

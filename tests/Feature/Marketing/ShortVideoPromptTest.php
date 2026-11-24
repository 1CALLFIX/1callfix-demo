<?php

namespace Tests\Feature\Marketing;

use App\Livewire\Marketing\SocialPosts;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\SocialPost;
use App\Services\Marketing\ShortVideoPrompt;
use App\Services\Marketing\SocialPostPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\TestCase;

/** 🎬 YouTube Short prompts (owner 2026-11-24): words for YouTube's AI video + our ending, Super Admin controlled. */
class ShortVideoPromptTest extends TestCase
{
    use RbacTestHelpers;
    use RefreshDatabase;

    private function makePost(array $over = []): SocialPost
    {
        return SocialPost::create(array_merge([
            'title' => 'AC service', 'caption_en' => '❄️ AC not cooling? Book a trusted AC pro on 1CallFix. https://1callfix.com/x #AC',
            'caption_te' => 'ఏసీ సర్వీస్ 1CallFix లో బుక్ చేసుకోండి.', 'module' => 'service', 'platforms' => ['facebook'],
            'status' => 'scheduled', 'scheduled_for' => CarbonImmutable::now(SocialPostPlanner::zone())->setTime(12, 0)->utc(), 'source' => 'manual',
        ], $over));
    }

    public function test_prompt_has_the_scene_the_presenter_and_the_spoken_line_in_english_and_telugu(): void
    {
        Setting::set(ShortVideoPrompt::PRESENTER_SETTING, 'woman');
        $p = ShortVideoPrompt::forPost($this->makePost(), 'Nellore');

        $this->assertSame('woman', $p['presenter']);
        $this->assertStringContainsString('Vertical 9:16 video, 8 seconds', $p['en']);
        $this->assertStringContainsString('Nellore, India', $p['en']);
        $this->assertStringContainsString('air conditioner', $p['en']);
        $this->assertStringContainsString('young Indian woman', $p['en']);
        $this->assertStringContainsString('"AC not cooling? Book a trusted AC pro on 1CallFix."', $p['en'], 'whole sentences, no emoji, link or hashtag');
        $this->assertStringNotContainsString('https://', $p['en']);
        $this->assertStringContainsString('The presenter is a host, not a customer.', $p['en']);
        $this->assertStringContainsString('No text, captions, logos or brand names on screen.', $p['en']);

        $this->assertStringContainsString('says in Telugu: "ఏసీ సర్వీస్ 1CallFix లో బుక్ చేసుకోండి."', $p['te'], 'the post\'s own Telugu words');
        $this->assertNull(ShortVideoPrompt::forPost($this->makePost(['caption_te' => null]))['te'], 'no Telugu words → no Telugu prompt');
    }

    public function test_an_offer_always_says_it_is_for_online_payment_in_both_languages(): void
    {
        $offer = $this->makePost(['source' => 'offer', 'caption_en' => '🎁 Diwali deal: use code DIWALI on 1CallFix.', 'caption_te' => '🎁 Diwali deal: 1CallFix లో DIWALI కోడ్ వాడండి.']);
        $p = ShortVideoPrompt::forPost($offer);
        $this->assertStringContainsString('The offer applies when you pay online.', $p['en']);
        $this->assertStringContainsString('ఆన్‌లైన్ చెల్లింపుకు మాత్రమే ఆఫర్ వర్తిస్తుంది.', $p['te']);

        // Any discount wording counts as an offer, even in a hand-written post.
        $manual = ShortVideoPrompt::forPost($this->makePost(['caption_en' => 'Get 20% off on AC service this week.']));
        $this->assertStringContainsString('pay online', $manual['en']);

        // A caption that already says it is not repeated.
        $idea = ShortVideoPrompt::forIdea(['source' => 'offer', 'source_ref' => '1', 'title' => 'Offer', 'module' => null,
            'caption_en' => 'Use code X on 1CallFix. Offer applies when you pay online.', 'caption_te' => '']);
        $this->assertSame(1, substr_count($idea['en'], 'pay online'));
    }

    public function test_presenter_takes_turns_or_follows_the_super_admin_choice(): void
    {
        $seen = collect(range(1, 12))->map(fn ($i) => ShortVideoPrompt::forIdea(['source' => 'app', 'source_ref' => "r{$i}", 'title' => 'App',
            'caption_en' => 'One app for everything.', 'caption_te' => '', 'module' => null])['presenter'])->unique()->sort()->values()->all();
        $this->assertSame(['man', 'woman'], $seen, 'take turns by default');

        Setting::set(ShortVideoPrompt::PRESENTER_SETTING, 'man');
        $this->assertSame('man', ShortVideoPrompt::forPost($this->makePost())['presenter']);
    }

    public function test_long_captions_are_cut_to_about_eight_seconds_of_speech(): void
    {
        $long = 'Book trusted electricians, plumbers and AC pros at your doorstep in Nellore. '.str_repeat('More words here again and again. ', 10);
        $p = ShortVideoPrompt::forPost($this->makePost(['caption_en' => $long]));
        preg_match('/says in clear, friendly Indian English: "([^"]+)"/', $p['en'], $m);
        $this->assertLessThanOrEqual(22, count(preg_split('/\s+/', trim($m[1]))));
        $this->assertStringStartsWith('Book trusted electricians', $m[1]);
    }

    public function test_the_team_sees_the_button_and_both_endings_only_while_super_admin_has_it_on(): void
    {
        $this->makePost();
        $team = $this->makeUserWithPermission('marketing.posts.manage', 'global');

        Livewire::actingAs($team)->test(SocialPosts::class)
            ->assertSeeHtml('data-shorts-open')->assertSeeHtml('data-shorts-prompt="en"')->assertSeeHtml('data-shorts-prompt="te"')
            ->assertSeeHtml('/videos/shorts-ending-en.mp4')->assertSeeHtml('/videos/shorts-ending-te.mp4');

        Setting::set(ShortVideoPrompt::ENABLED_SETTING, '0');
        Livewire::actingAs($team)->test(SocialPosts::class)->assertDontSeeHtml('data-shorts-open');

        $this->assertFileExists(public_path('videos/shorts-ending-en.mp4'));
        $this->assertFileExists(public_path('videos/shorts-ending-te.mp4'));
    }

    public function test_only_super_admin_can_switch_it_and_pick_the_presenter_and_it_is_logged(): void
    {
        $team = $this->makeUserWithPermission('marketing.posts.manage', 'global');
        Livewire::actingAs($team)->test(SocialPosts::class)->assertDontSeeHtml('data-shorts-settings')
            ->call('setShorts', false)->assertForbidden();
        Livewire::actingAs($team)->test(SocialPosts::class)->call('setShortsPresenter', 'man')->assertForbidden();
        $this->assertTrue(ShortVideoPrompt::enabled());

        $boss = $this->makeSuperAdmin();
        Livewire::actingAs($boss)->test(SocialPosts::class)->assertSeeHtml('data-shorts-settings')
            ->call('setShorts', false)->call('setShortsPresenter', 'woman');
        $this->assertFalse(ShortVideoPrompt::enabled());
        $this->assertSame('woman', ShortVideoPrompt::presenterSetting());
        $this->assertTrue(ActivityLog::where('causer_id', $boss->id)->where('description', 'Setting '.ShortVideoPrompt::PRESENTER_SETTING.' changed')->exists(), 'setting changes are audited');

        Livewire::actingAs($boss)->test(SocialPosts::class)->call('setShortsPresenter', 'robot')->assertStatus(422);
    }
}

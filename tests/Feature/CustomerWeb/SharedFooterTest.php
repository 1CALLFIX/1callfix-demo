<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\ContentPage;
use App\Models\Setting;
use App\Support\PartnerPage\FooterLinks;
use App\Support\PartnerPage\PartnerPageSettings as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * REF 1CF-PARTNER-PAGE-001, B5: the shared customer footer is admin-controlled, and with nothing saved it renders
 * exactly what it rendered before (baseline = origin/main's footer output, see snapshot()).
 */
class SharedFooterTest extends TestCase
{
    use RefreshDatabase;

    public static function normalise(string $html): string
    {
        $start = strpos($html, '<footer');
        $end = strpos($html, '</footer>');
        $footer = substr($html, $start, $end - $start + strlen('</footer>'));

        return trim((string) preg_replace('/\s+/', ' ', str_replace((string) now()->year, '{YEAR}', $footer)));
    }

    private function footerOf(string $uri): string
    {
        return self::normalise($this->get($uri)->assertOk()->getContent());
    }

    /**
     * The baseline is the footer as rendered by origin/main (9af9569, captured in a clean worktree of that commit).
     * The ONLY allowed difference is the partner link: /coming-soon/partners became /partners (301 from the old one).
     */
    private function snapshot(): string
    {
        $raw = trim((string) file_get_contents(__DIR__.'/fixtures/footer_origin_main.html'));
        $this->assertSame(1, substr_count($raw, '/coming-soon/partners'), 'baseline must hold exactly one partner link');

        return str_replace('/coming-soon/partners', '/partners', $raw);
    }

    public function test_with_no_settings_saved_the_footer_equals_the_snapshot_on_every_kind_of_page(): void
    {
        $this->assertSame($this->snapshot(), $this->footerOf('/'));
        $this->assertSame($this->snapshot(), $this->footerOf('/partners'));
        $this->assertSame($this->snapshot(), $this->footerOf('/help'));
    }

    public function test_every_existing_footer_link_is_in_the_default(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        foreach ([
            route('customer.how-it-works'), route('customer.help'), route('customer.services.index'), route('customer.categories.index'),
            route('customer.offers'), route('customer.partners'), route('customer.privacy'), route('customer.terms'),
        ] as $href) {
            $this->assertStringContainsString('href="'.$href.'"', $html);
        }
        foreach (['How It Works', 'Help &amp; FAQs', 'Browse services', 'Categories', 'Offers', 'Join as a Partner', 'Privacy Policy', 'Terms of Use'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    public function test_a_valid_admin_list_replaces_the_groups_and_cms_footer_pages_still_join_company(): void
    {
        ContentPage::create(['slug' => 'about-us', 'title' => 'About us', 'content' => 'x', 'is_active' => true, 'show_in_footer' => true, 'footer_order' => 1]);
        Setting::set(P::key('footer.groups'), json_encode([
            ['title' => 'Company', 'links' => [['label' => 'Our story', 'href' => '/our-story']]],
            ['title' => 'Talk to us', 'links' => [['label' => 'Email', 'href' => 'mailto:hello@example.com'], ['label' => 'Docs', 'href' => 'https://example.com/docs']]],
        ]));

        $this->get('/')->assertOk()->assertSeeText('Our story')->assertSeeText('Talk to us')->assertSeeText('About us')
            ->assertDontSeeText('Browse services')->assertSee('href="mailto:hello@example.com"', false);
    }

    public function test_empty_invalid_or_schema_failing_settings_render_the_default(): void
    {
        foreach ([
            '',                                                   // empty
            '{not json',                                          // invalid
            json_encode([]),                                      // empty list
            json_encode([['title' => 'X', 'links' => [['label' => 'Bad', 'href' => 'javascript:alert(1)']]]]), // unsafe address
            json_encode([['title' => 'X', 'links' => []]]),       // no links
            json_encode([['title' => str_repeat('t', 80), 'links' => [['label' => 'a', 'href' => '/a']]]]), // too long
            json_encode('just a string'),
            json_encode(array_fill(0, 7, ['title' => 'X', 'links' => [['label' => 'a', 'href' => '/a']]])), // too many groups
            json_encode([['title' => 'X', 'links' => [['label' => 'a', 'href' => '//evil.example']]]]),     // protocol-relative
        ] as $bad) {
            Setting::set(P::key('footer.groups'), $bad);
            Cache::flush();
            $this->assertSame($this->snapshot(), $this->footerOf('/'), 'value: '.$bad);
        }
    }

    public function test_a_settings_or_cache_failure_renders_the_default(): void
    {
        Cache::shouldReceive('rememberForever')->andThrow(new \RuntimeException('cache down'));

        $this->assertNull(FooterLinks::custom());
        $this->assertNull(FooterLinks::contactLine());
        $this->assertSame(array_keys(FooterLinks::defaults()), array_keys(FooterLinks::groups()));
    }

    public function test_the_admin_screen_refuses_an_invalid_footer_list(): void
    {
        $this->assertArrayHasKey('footer.groups', P::validate(['footer.groups' => json_encode([['title' => 'X', 'links' => [['label' => 'a', 'href' => 'javascript:1']]]])]));
        $this->assertArrayHasKey('footer.groups', P::validate(['footer.groups' => json_encode([['title' => 'X', 'links' => [['label' => 'a [b]', 'href' => '/a']]]])]));
        $this->assertSame([], P::validate(['footer.groups' => json_encode([['title' => 'X', 'links' => [['label' => 'a', 'href' => '/a']]]])]));
    }

    public function test_the_contact_line_shows_only_when_set(): void
    {
        $this->get('/')->assertOk()->assertDontSeeText('Call us on');

        Setting::set(P::key('footer_contact'), 'Call us on 0861 000 0000');
        $this->get('/')->assertOk()->assertSeeText('Call us on 0861 000 0000');
    }
}

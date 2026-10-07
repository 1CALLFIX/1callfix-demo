<?php

namespace Tests\Feature\CustomerWeb;

use App\Models\PartnerBenefit;
use App\Support\PartnerPage\PartnerPageSettings as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REF 1CF-PARTNER-PAGE-001: code defaults must fill every block the admin has not saved, whatever the state of the
 * settings cache (cold, warm, or holding an empty array).
 */
class PartnerPageDefaultsFillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PartnerBenefit::query()->delete();
    }

    private function assertFullPage(string $html): void
    {
        $this->assertStringContainsString('Get job offers from customers near you', $html, 'hero title');
        $this->assertStringContainsString('Join 1CallFix as a partner.', $html, 'hero subtitle');
        $this->assertSame(9, substr_count($html, 'data-role="'), 'nine role cards');
        foreach (P::defaultSteps() as $step) {
            $this->assertStringContainsString($step['title'], $html, 'step: '.$step['title']);
        }
        $this->assertCount(4, P::defaultSteps());
        $this->assertCount(6, P::defaultBenefits());
        foreach (P::defaultBenefits() as $tile) {
            $this->assertStringContainsString($tile['title'], $html, 'tile: '.$tile['title']);
        }
        foreach (P::defaultFaq() as $item) {
            $this->assertStringContainsString($item['q'], $html, 'faq: '.$item['q']);
        }
        foreach (P::lines('needs') as $need) {
            $this->assertStringContainsString($need, $html, 'need: '.$need);
        }
    }

    public function test_cold_cache(): void
    {
        cache()->forget(P::CACHE_KEY);
        $this->assertFullPage($this->get(route('customer.partners'))->assertOk()->getContent());
    }

    public function test_warm_cache(): void
    {
        $this->get(route('customer.partners'))->assertOk();
        $this->assertTrue(cache()->has(P::CACHE_KEY));
        $this->assertFullPage($this->get(route('customer.partners'))->assertOk()->getContent());
    }

    public function test_empty_cache_array(): void
    {
        cache()->forever(P::CACHE_KEY, []);
        $this->assertFullPage($this->get(route('customer.partners'))->assertOk()->getContent());
    }

    public function test_footer_links_present_on_cold_warm_and_empty_cache(): void
    {
        foreach ([fn () => cache()->forget(P::CACHE_KEY), fn () => null, fn () => cache()->forever(P::CACHE_KEY, [])] as $prep) {
            $prep();
            $html = $this->get(route('customer.partners'))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<footer[\s\S]*<a [^>]*href="[^"]+"#', $html);
            $this->assertStringContainsString('/partners', $html);
        }
    }
}

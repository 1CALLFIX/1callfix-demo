<?php

namespace Tests\Feature\CustomerWeb;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** REF 1CF-PARTNER-PAGE-001: /partners alone drops the customer search header and bottom nav; the shared footer stays. */
class PartnerPageChromeTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = '<header class="sticky top-0 z-40';

    private const BOTTOM_NAV = 'aria-label="Primary mobile"';

    public function test_partners_hides_the_customer_header_and_bottom_nav_but_keeps_the_footer(): void
    {
        $html = $this->get(route('customer.partners'))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::HEADER, $html);
        $this->assertStringNotContainsString(self::BOTTOM_NAV, $html);
        $this->assertStringContainsString('<footer', $html);
        $this->assertStringContainsString('Partner login', $html);
    }

    public function test_other_customer_pages_are_unchanged(): void
    {
        foreach (['/', '/help'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringContainsString(self::HEADER, $html, $path.' header');
            $this->assertStringContainsString(self::BOTTOM_NAV, $html, $path.' bottom nav');
            $this->assertStringContainsString('<footer', $html, $path.' footer');
        }
    }
}

<?php

namespace Tests\Feature\Ai;

use App\Contracts\NarrativeAiAdapter;
use App\Services\Ai\DailyInsightsService;
use App\Services\Ai\LogNarrativeAiAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Admin Polish + AI session, Part 2 items 3-4 — "if the AI call fails or is
 * disabled, the underlying data/detection must still work and display
 * correctly, just without the natural-language layer" and "needs a test
 * confirming graceful fallback when it's unavailable, not a test asserting
 * exact wording" (mission's own instruction — no wording assertions below).
 */
class DailyInsightsServiceTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_default_binding_is_the_log_adapter_with_no_real_ai_key_configured(): void
    {
        // Confirms this repo's own default state — the same standard
        // LogSmsAdapter's docblock holds itself to.
        $this->assertInstanceOf(LogNarrativeAiAdapter::class, app(NarrativeAiAdapter::class));
    }

    public function test_summary_is_null_and_facts_still_populate_when_ai_is_unavailable(): void
    {
        $scenario = $this->makeBookingScenario('searching_provider');
        $scenario['booking']->forceFill(['created_at' => now()->subHours(2)])->save(); // past the default 30-minute stuck threshold
        $admin = $this->makeUserWithPermission('operations.view', 'global');

        $digest = app(DailyInsightsService::class)->digest($admin);

        $this->assertNull($digest['summary'], 'LogNarrativeAiAdapter must return null so the caller falls back to plain facts.');
        $this->assertNotEmpty($digest['facts'], 'The real facts must still be present even though AI phrasing is unavailable.');
        $this->assertStringContainsString($scenario['booking']->code, $digest['facts'][0]);
    }

    public function test_empty_facts_never_call_the_ai_adapter_at_all(): void
    {
        $admin = $this->makeUserWithPermission('operations.view', 'global');

        $digest = app(DailyInsightsService::class)->digest($admin);

        $this->assertSame([], $digest['facts']);
        $this->assertNull($digest['summary']);
    }

    public function test_summary_reflects_a_fake_ai_adapter_when_one_is_bound(): void
    {
        // Proves the adapter is genuinely swappable (mission's own "keep it
        // isolated... so it can be swapped, disabled, or mocked in tests")
        // without asserting anything about real wording/prompt content.
        $this->app->bind(NarrativeAiAdapter::class, fn () => new class implements NarrativeAiAdapter {
            public function summarize(array $facts): ?string
            {
                return 'FAKE SUMMARY: '.count($facts).' fact(s).';
            }
        });

        $scenario = $this->makeBookingScenario('searching_provider');
        $scenario['booking']->forceFill(['created_at' => now()->subHours(2)])->save();
        $admin = $this->makeUserWithPermission('operations.view', 'global');

        $digest = app(DailyInsightsService::class)->digest($admin);

        $this->assertSame('FAKE SUMMARY: 1 fact(s).', $digest['summary']);
    }
}

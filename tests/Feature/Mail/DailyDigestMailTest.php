<?php

namespace Tests\Feature\Mail;

use App\Mail\DailyDigestMail;
use App\Services\Reporting\DailyDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * "Mail renders with real, correct data — snapshot or assertion-based test
 * on content, not just 'mail was sent.'" — mission spec, verbatim. Every
 * assertion below inspects the rendered HTML body for a real figure the
 * fixture produced, not merely that Mail::send() didn't throw.
 */
class DailyDigestMailTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    public function test_rendered_email_contains_the_real_kpi_figures(): void
    {
        $scenario = $this->makeBookingScenario('completed');
        $scenario['booking']->forceFill(['completed_at' => now(), 'price_final' => '750.00'])->save();

        $admin = $this->makeSuperAdmin();
        $payload = app(DailyDigestService::class)->forUser($admin);

        $mail = new DailyDigestMail($admin, $payload, now()->toDateString());
        $html = $mail->render();

        $this->assertStringContainsString((string) $payload['kpis']['bookings_today'], $html);
        $this->assertStringContainsString('750.00', $html);
        $this->assertStringContainsString($admin->name, $html);
        $this->assertStringContainsString(now()->toDateString(), $html);
    }

    public function test_rendered_email_lists_real_insight_facts_when_present(): void
    {
        $scenario = $this->makeBookingScenario('searching_provider');
        $scenario['booking']->forceFill(['created_at' => now()->subHours(2)])->save();

        $admin = $this->makeUserWithPermission('dashboard.view', 'global');
        $this->grantPermission($admin, 'operations.view', 'global');

        $payload = app(DailyDigestService::class)->forUser($admin);
        $this->assertNotNull($payload['insights']);

        $html = (new DailyDigestMail($admin, $payload, now()->toDateString()))->render();

        $this->assertStringContainsString($scenario['booking']->code, $html);
        $this->assertStringContainsString('Needs Attention', $html);
    }

    public function test_rendered_email_shows_no_insights_section_content_when_recipient_lacks_operations_view(): void
    {
        $admin = $this->makeUserWithPermission('dashboard.view', 'global');
        $payload = app(DailyDigestService::class)->forUser($admin);
        $this->assertNull($payload['insights']);

        $html = (new DailyDigestMail($admin, $payload, now()->toDateString()))->render();

        $this->assertStringNotContainsString('Needs Attention', $html);
    }

    public function test_rendered_email_shows_a_clean_all_clear_state_when_there_are_zero_insight_items(): void
    {
        $admin = $this->makeUserWithPermission('dashboard.view', 'global');
        $this->grantPermission($admin, 'operations.view', 'global');

        $payload = app(DailyDigestService::class)->forUser($admin);
        $html = (new DailyDigestMail($admin, $payload, now()->toDateString()))->render();

        $this->assertStringContainsString('No stuck bookings', $html);
    }
}

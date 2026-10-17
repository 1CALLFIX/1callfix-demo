<?php

namespace Tests\Feature\Housekeeping;

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — Stage 1 housekeeping.
 */
class TestIsolationAndDeploySafetyTest extends TestCase
{
    // ───────────── 1.1 stray HTTP is blocked outside the external group ─────────────

    public function test_an_unfaked_outbound_request_fails_the_test(): void
    {
        $this->expectException(StrayRequestException::class);

        Http::get('https://api.razorpay.com/v1/orders');
    }

    public function test_a_faked_request_is_still_allowed(): void
    {
        Http::fake(['example.test/*' => Http::response(['ok' => true])]);

        $this->assertTrue(Http::get('https://example.test/ping')->json('ok'));
    }

    #[Group('external')]
    public function test_the_external_group_is_not_guarded(): void
    {
        $this->assertFalse(Http::preventingStrayRequests(), 'external tests may reach the network');
    }

    // ───────────── 1.2 composer setup refuses production ─────────────

    private function runGuard(array $env): Process
    {
        $process = new Process([PHP_BINARY, base_path('scripts/refuse-in-production.php')], base_path(), $env);
        $process->run();

        return $process;
    }

    public function test_setup_guard_refuses_app_env_production(): void
    {
        $p = $this->runGuard(['APP_ENV' => 'production']);

        $this->assertSame(1, $p->getExitCode());
        $this->assertStringContainsString('Refusing to run `composer setup`: APP_ENV=production', $p->getErrorOutput());
    }

    public function test_setup_guard_allows_non_production(): void
    {
        foreach (['local', 'testing', 'staging'] as $env) {
            $this->assertSame(0, $this->runGuard(['APP_ENV' => $env])->getExitCode(), $env);
        }
    }

    public function test_setup_guard_is_the_first_step_of_composer_setup(): void
    {
        $scripts = json_decode(file_get_contents(base_path('composer.json')), true)['scripts']['setup'];

        $this->assertSame('@php scripts/refuse-in-production.php', $scripts[0]);
    }

    public function test_runbook_no_longer_recommends_scp_of_a_whole_folder(): void
    {
        $runbook = file_get_contents(base_path('docs/DEPLOYMENT_RUNBOOK.md'));

        // CLAUDE.md deploy rule: code reaches the server through git only (no archive/tarball of source).
        $this->assertStringNotContainsString('# scp -r <local-build-folder>', $runbook);
        $this->assertStringNotContainsString('git archive', $runbook);
        $this->assertStringContainsString('git fetch origin && git merge --ff-only origin/main', $runbook);
    }
}

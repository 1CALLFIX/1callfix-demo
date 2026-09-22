<?php

namespace Tests\Feature\ProviderWeb;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 1CF-FIX-ALERT-002 — the browser-side half, run against the real sources:
 *
 *   tests/js/provider-ring-lock.test.mjs   one tab rings; no duplicate
 *                                          timers/listeners across navigation
 *   tests/js/push-registration.test.mjs    getToken() waits for an ACTIVE
 *                                          service worker; the click handler
 *                                          opens the offer link on its own origin
 *
 * The service-worker files also get a cheap static guard here so a refactor
 * that drops the behaviour fails even where node is unavailable.
 */
class ProviderAlertRoutingJsTest extends TestCase
{
    public function test_the_service_worker_is_served_from_the_web_root_and_keeps_its_notification_behaviour(): void
    {
        $sw = file_get_contents(public_path('firebase-messaging-sw.js'));

        $this->assertStringContainsString('requireInteraction: true', $sw);
        $this->assertStringContainsString('function sameOriginLink(link)', $sw);
        $this->assertStringContainsString('sameOriginLink((event.notification.data && event.notification.data.link)', $sw);
        $this->assertStringContainsString('self.clients.claim()', $sw);
    }

    public function test_registration_waits_for_activation_before_asking_for_a_token(): void
    {
        $js = file_get_contents(resource_path('js/push-notifications.js'));

        $this->assertStringContainsString("register(SW_URL, { scope: '/' })", $js);
        $this->assertStringContainsString('whenActivated(pending)', $js);
        $this->assertSame(1, substr_count($js, 'navigator.serviceWorker.register('), 'exactly one register() call site');
        $this->assertStringContainsString('registrationPromise', $js);
    }

    public function test_the_browser_behaviour_suites_pass_against_the_real_sources(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node is not installed; run tests/js/provider-ring-lock.test.mjs and tests/js/push-registration.test.mjs where it is.');
        }

        $process = new Process([
            $node, '--test',
            base_path('tests/js/provider-ring-lock.test.mjs'),
            base_path('tests/js/push-registration.test.mjs'),
        ], base_path(), null, null, 120);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "Provider alert browser suites failed:\n".$process->getOutput().$process->getErrorOutput(),
        );
    }
}

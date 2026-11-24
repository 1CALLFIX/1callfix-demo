<?php

namespace Tests\Feature\Mobile;

use App\Contracts\PushAdapter;
use App\Notifications\Channels\PushChannel;
use App\Support\PushApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Owner report 2026-11-24: "the provider gets the notification, but pressing it opens the app logged out". The apps
 * open www.1callfix.com, the server builds links on APP_URL (1callfix.com, no www), and a sign-in cookie belongs to
 * one exact address, so the tapped page was a signed-out one. App pushes now carry the app's own address.
 */
class AppPushLinkTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // As on live: queued pushes build links from APP_URL, without www.
        config(['app.url' => 'https://1callfix.com', 'app.native_app_url' => 'https://www.1callfix.com']);
        URL::forceRootUrl('https://1callfix.com');
        URL::forceScheme('https');
    }

    public function test_our_links_move_to_the_app_address_and_keep_their_page_and_query(): void
    {
        $this->assertSame('https://www.1callfix.com/provider/jobs?offer=12#now', PushApp::appLink('https://1callfix.com/provider/jobs?offer=12#now'));
        $this->assertSame('https://www.1callfix.com/provider', PushApp::appLink('https://www.1callfix.com/provider'));
        $this->assertSame('https://www.1callfix.com/rider', PushApp::appLink('/rider'), 'a bare path goes on the address, not on /app/partner');
        $this->assertSame('https://www.1callfix.com/', PushApp::appLink('https://1callfix.com'));
        $this->assertSame('https://maps.google.com/?q=x', PushApp::appLink('https://maps.google.com/?q=x'), 'someone else\'s link is left alone');
    }

    public function test_a_tapped_notification_in_the_partner_app_opens_the_signed_in_address_and_browsers_are_unchanged(): void
    {
        $sent = [];
        $adapter = new class($sent) implements PushAdapter
        {
            public function __construct(public array &$sent) {}

            public function send(string $token, string $title, string $body, array $data = []): bool
            {
                $this->sent[$token] = $data;

                return true;
            }
        };
        $wake = new class extends Notification
        {
            public function pushApp(): string
            {
                return 'partner';
            }

            public function toPush($n): array
            {
                return ['title' => '🔔 New job near you', 'body' => 'Open the app and go online to take it.'];
            }

            public function pushLink($n): string
            {
                return route('provider.dashboard');
            }
        };
        $this->assertSame('https://1callfix.com/provider', $wake->pushLink(null), 'the link as the server builds it');

        $inApp = $this->makeCustomer();
        PushApp::register($inApp, 'partner', 'partner-phone');
        $inBrowser = $this->makeCustomer();
        $inBrowser->update(['fcm_token' => 'browser']);

        (new PushChannel($adapter))->send($inApp, $wake);
        (new PushChannel($adapter))->send($inBrowser, $wake);

        $this->assertSame('https://www.1callfix.com/provider', $sent['partner-phone']['link']);
        $this->assertSame('https://1callfix.com/provider', $sent['browser']['link'], 'the browser opens it on its own address itself (service worker)');
    }

    public function test_the_app_address_setting_is_the_address_the_apps_really_open(): void
    {
        $cfg = json_decode(file_get_contents(base_path('mobile/capacitor.config.json')), true);
        $origin = parse_url($cfg['server']['url'], PHP_URL_SCHEME).'://'.parse_url($cfg['server']['url'], PHP_URL_HOST);

        // The rider and partner apps use the same address (mobile/scripts/flavor-configs.mjs).
        $this->assertStringContainsString("env('NATIVE_APP_URL', '{$origin}')", file_get_contents(config_path('app.php')),
            'config app.native_app_url must default to the address in mobile/capacitor.config.json');
    }
}

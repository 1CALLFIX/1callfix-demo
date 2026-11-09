<?php

namespace Tests\Feature;

use App\Support\MapLink;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Owner 2026-11-09 (photo: "Directions" in the Rider app opened google.com/maps in Chrome, a tiny desktop map):
 * every map link opens the phone's maps app. Android (our apps and Chrome) gets an intent:// link aimed at Google
 * Maps with the web page as fallback, iPhone gets Apple Maps, computers keep the Google Maps page in a new tab.
 */
class MapLinkTest extends TestCase
{
    private const RIDER_APP = 'Mozilla/5.0 (Linux; Android 13; SM-M135F; wv) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36 1CallFixApp/rider';
    private const ANDROID_CHROME = 'Mozilla/5.0 (Linux; Android 13; SM-M135F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';
    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1';
    private const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

    private function as(string $ua): void
    {
        request()->headers->set('User-Agent', $ua);
    }

    public function test_the_rider_app_and_android_chrome_open_the_google_maps_app_and_start_the_drive(): void
    {
        foreach ([self::RIDER_APP, self::ANDROID_CHROME] as $ua) {
            $this->as($ua);
            $this->assertSame(
                'intent://www.google.com/maps/dir/?api=1&destination=14.4426,79.9865&dir_action=navigate'
                .'#Intent;scheme=https;package=com.google.android.apps.maps;S.browser_fallback_url='
                .rawurlencode('https://www.google.com/maps/dir/?api=1&destination=14.4426,79.9865&dir_action=navigate').';end',
                MapLink::directions('14.442600', '79.986500'),
            );
            $this->assertStringStartsWith('intent://www.google.com/maps/search/?api=1&query=14.4426,79.9865#Intent;', MapLink::place(14.4426, 79.9865));
        }
    }

    public function test_iphone_opens_apple_maps_and_a_computer_keeps_the_web_page(): void
    {
        $this->as(self::IPHONE);
        $this->assertSame('https://maps.apple.com/?daddr=14.4426,79.9865&dirflg=d', MapLink::directions(14.4426, 79.9865));
        $this->assertSame('https://maps.apple.com/?ll=14.4426,79.9865&q=14.4426,79.9865', MapLink::place(14.4426, 79.9865));

        $this->as(self::DESKTOP);
        $this->assertSame('https://www.google.com/maps/dir/?api=1&destination=14.4426,79.9865', MapLink::directions(14.4426, 79.9865));
    }

    public function test_no_location_means_no_link(): void
    {
        $this->as(self::RIDER_APP);
        $this->assertNull(MapLink::directions(null, null));
        $this->assertNull(MapLink::directions(0, 0));
        $this->assertSame('', trim(Blade::render('<x-ui.map-link :lat="null" :lng="null">Directions</x-ui.map-link>')));
    }

    public function test_a_pasted_google_maps_link_opens_in_the_app_and_other_links_are_left_alone(): void
    {
        $this->as(self::ANDROID_CHROME);
        $this->assertStringStartsWith('intent://maps.app.goo.gl/AbC123#Intent;scheme=https;package=com.google.android.apps.maps;', MapLink::open('https://maps.app.goo.gl/AbC123'));
        $this->assertSame('https://example.com/where', MapLink::open('https://example.com/where'));
    }

    public function test_the_link_opens_without_an_empty_tab_on_phones_and_in_a_new_tab_on_computers(): void
    {
        $this->as(self::RIDER_APP);
        $html = Blade::render('<x-ui.map-link :lat="14.4426" :lng="79.9865" class="btn">Directions</x-ui.map-link>');
        $this->assertStringContainsString('href="intent://www.google.com/maps/dir/', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
        $this->assertStringContainsString('class="btn"', $html);

        $this->as(self::DESKTOP);
        $this->assertStringContainsString('target="_blank"', Blade::render('<x-ui.map-link :lat="14.4426" :lng="79.9865">Directions</x-ui.map-link>'));
    }
}

<?php

namespace Tests\Feature\ProviderWeb;

use App\Livewire\Provider\Dashboard;
use App\Livewire\Provider\Jobs\Index;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Provider alert DELIVERY architecture (1CF-ALERT-045).
 *
 * The bug this pins: provider-alerts.js used to be a raw `<script defer>` in
 * the layout <body>. wire:navigate re-evaluates body scripts on every visit,
 * so the second visit threw `CHIME_INTERVAL_MS has already been declared`
 * and stacked duplicate listeners/timers. It is now a Vite entry in <head>
 * (evaluated once per document), like push-notifications.js. These tests
 * lock the wiring; the audio/Alpine behaviour itself is browser-only.
 */
class ProviderAlertDeliveryTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function layout(): string
    {
        return file_get_contents(resource_path('views/components/layouts/provider.blade.php'));
    }

    private function alertJs(): string
    {
        return file_get_contents(resource_path('js/provider-alerts.js'));
    }

    private function liveOffer(Booking $booking, Provider $provider, ?\DateTimeInterface $at = null): DispatchAttempt
    {
        return DispatchAttempt::create([
            'booking_id' => $booking->id,
            'provider_id' => $provider->id,
            'status' => 'notified',
            'distance_km' => 2.4,
            'notified_at' => $at ?? now(),
        ]);
    }

    public function test_layout_loads_provider_alerts_through_the_vite_entry_list(): void
    {
        $this->assertMatchesRegularExpression(
            "/@vite\(\[[^\]]*'resources\/js\/provider-alerts\.js'[^\]]*\]\)/",
            $this->layout(),
        );
        // Same pipeline as push-notifications.js, and registered as a build input.
        $this->assertStringContainsString("'resources/js/push-notifications.js'", $this->layout());
        $this->assertStringContainsString("'resources/js/provider-alerts.js'", file_get_contents(base_path('vite.config.js')));
    }

    public function test_the_raw_body_script_and_the_static_file_are_gone(): void
    {
        $this->assertStringNotContainsString("asset('js/provider-alerts.js')", $this->layout());
        $this->assertStringNotContainsString('<script src=', $this->layout());
        $this->assertFileDoesNotExist(public_path('js/provider-alerts.js'));
    }

    public function test_rendered_provider_page_serves_the_module_from_head_only(): void
    {
        $s = $this->makeBookingScenario('searching_provider');

        $html = $this->actingAs($s['provider']->user)->get(route('provider.dashboard'))->assertOk()->getContent();

        $head = substr($html, 0, strpos($html, '</head>'));
        $this->assertSame(1, preg_match_all('/<script[^>]+type="module"[^>]+provider-alerts[^>]*>/', $head));
        $this->assertSame(0, preg_match_all('/provider-alerts/', substr($html, strlen($head))));
    }

    public function test_alert_module_keeps_the_event_contract_and_is_idempotent(): void
    {
        $js = $this->alertJs();

        $this->assertStringContainsString("'provider-alert-offers'", $js);
        $this->assertStringContainsString("'provider-alert-status'", $js);
        // Module-level once-guard and Alpine lifecycle pairing.
        $this->assertStringContainsString('window[GUARD]', $js);
        $this->assertStringContainsString("Alpine.data('providerOfferAlert'", $js);
        $this->assertStringContainsString('alpine:init', $js);
        $this->assertStringContainsString('destroy()', $js);
        $this->assertStringContainsString('removeEventListener', $js);
        // No audio asset, no service-worker audio.
        $this->assertDoesNotMatchRegularExpression('/\.(mp3|ogg|wav)\b/i', $js);
        $this->assertStringNotContainsString('serviceWorker', $js);
    }

    public function test_offer_list_countdown_is_a_cleaned_up_alpine_component_not_an_inline_interval(): void
    {
        $view = file_get_contents(resource_path('views/livewire/provider/jobs/index.blade.php'));

        // The old `x-init="const t = setInterval(...)"` was never cleared, so
        // it kept mutating a destroyed scope after navigation
        // ("Alpine Expression Error: n is not defined").
        $this->assertDoesNotMatchRegularExpression('/x-init="[^"]*setInterval/', $view);
        $this->assertStringNotContainsString('x-data="{ n:', $view);
        $this->assertStringContainsString('x-data="providerOfferCountdown(', $view);

        $js = $this->alertJs();
        $this->assertStringContainsString("Alpine.data('providerOfferCountdown'", $js);
        $this->assertMatchesRegularExpression('/destroy:\s*stop/', $js);
    }

    public function test_layout_banner_is_the_alpine_component_and_leaves_offer_controls_alone(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString('x-data="providerOfferAlert"', $layout);
        $this->assertStringContainsString('New job offer', $layout);
        $this->assertStringContainsString('role="timer"', $layout);

        // In normal flow — a fixed/sticky banner could cover Accept/Decline.
        $banner = substr($layout, strpos($layout, 'x-data="providerOfferAlert"'));
        $banner = substr($banner, 0, strpos($banner, '</section>'));
        $this->assertStringNotContainsString('fixed', $banner);
        $this->assertStringNotContainsString('sticky', $banner);
    }

    public function test_offers_event_keeps_count_and_adds_display_summaries(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $attempt = $this->liveOffer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)->assertDispatched('provider-alert-offers', function ($name, $params) use ($attempt) {
            $offer = $params['offers'][0] ?? [];

            return $params['count'] === 1
                && count($params['offers']) === 1
                && $offer['id'] === $attempt->booking_id
                && $offer['code'] === $attempt->booking->code
                && str_starts_with($offer['price'], '₹')
                && $offer['distance'] === '2.4 km'
                && $offer['expires_in'] > 0 && $offer['expires_in'] <= 25;
        });
    }

    public function test_dashboard_event_carries_the_same_summaries(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $this->liveOffer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Dashboard::class)->assertDispatched('provider-alert-offers',
            fn ($name, $params) => $params['count'] === 1
                && ($params['offers'][0]['id'] ?? null) === $s['booking']->id
                && ($params['offers'][0]['expires_in'] ?? 0) > 0);
    }

    public function test_a_stale_offer_is_absent_from_the_summary_so_the_client_stops_ringing(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $this->liveOffer($s['booking'], $s['provider'], now()->subSeconds(120));
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)->assertDispatched('provider-alert-offers',
            fn ($name, $params) => $params['count'] === 0 && $params['offers'] === []);
        Livewire::test(Dashboard::class)->assertDispatched('provider-alert-offers',
            fn ($name, $params) => $params['count'] === 0 && $params['offers'] === []);
    }

    public function test_declining_drops_the_offer_from_the_next_event(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $this->liveOffer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)
            ->call('decline', $s['booking']->id)
            ->assertDispatched('provider-alert-offers',
                fn ($name, $params) => $params['count'] === 0 && $params['offers'] === []);
    }
}

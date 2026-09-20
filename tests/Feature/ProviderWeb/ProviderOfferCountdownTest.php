<?php

namespace Tests\Feature\ProviderWeb;

use App\Livewire\Provider\Jobs\Index;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Job Offers countdown pill regression (1CF-ALERT-COUNTDOWN-FIX).
 *
 * The pill used to be `x-data="providerOfferCountdown({{ $expiresIn }})"`. The
 * argument is the seconds left, so that attribute changed on EVERY wire:poll
 * render; Alpine then rebuilt the component in place while the pill's x-text
 * stayed bound to the old scope, and the visible count froze between renders
 * (it moved in 4 s steps instead of every second).
 *
 * The fix keeps x-data constant and ships the seconds in `data-seconds`. What
 * can be pinned without a browser lives here:
 *
 *   - the rendered markup keeps x-data identical across server renders while
 *     data-seconds moves (the root cause, in PHP);
 *   - the component's behaviour — per-second ticking, picking up a new server
 *     value, interval cleanup on destroy, no duplicate intervals — is asserted
 *     against the real resources/js/provider-alerts.js by
 *     tests/js/provider-offer-countdown.test.mjs, which this class runs.
 *
 * The "n is not defined" warning that appears when a poll response lands after
 * wire:navigate is a separate, pre-existing Alpine/Livewire teardown effect and
 * is deliberately not covered or changed here.
 */
class ProviderOfferCountdownTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function liveOffer(Booking $booking, Provider $provider): DispatchAttempt
    {
        return DispatchAttempt::create([
            'booking_id' => $booking->id,
            'provider_id' => $provider->id,
            'status' => 'notified',
            'distance_km' => 2.4,
            'notified_at' => now(),
        ]);
    }

    /** The countdown pill's opening tag, or fail. */
    private function pillTag(string $html): string
    {
        $this->assertSame(
            1,
            preg_match('/<span\b[^>]*x-data="providerOfferCountdown[^>]*>/s', $html, $m),
            'Expected exactly one countdown pill in the offers list',
        );

        return $m[0];
    }

    public function test_pill_x_data_is_constant_and_the_changing_seconds_live_in_data_seconds(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $this->liveOffer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        $component = Livewire::test(Index::class);
        $first = $this->pillTag($component->html());

        // A wire:poll render 7 s later.
        $this->travel(7)->seconds();
        $component->call('$refresh');
        $second = $this->pillTag($component->html());

        // Root cause pinned: no argument, identical across renders, so a poll
        // morph never touches x-data and Alpine never rebuilds the component.
        $this->assertStringContainsString('x-data="providerOfferCountdown"', $first);
        $this->assertStringNotContainsString('providerOfferCountdown(', $first);
        $this->assertSame(
            preg_match('/x-data="[^"]*"/', $first, $a) ? $a[0] : null,
            preg_match('/x-data="[^"]*"/', $second, $b) ? $b[0] : null,
            'x-data must not change between renders',
        );

        // ...while the server's seconds-left do move, and are what the client re-syncs to.
        preg_match('/data-seconds="(\d+)"/', $first, $f);
        preg_match('/data-seconds="(\d+)"/', $second, $g);
        $this->assertNotEmpty($f, 'data-seconds missing on first render');
        $this->assertNotEmpty($g, 'data-seconds missing on second render');
        $this->assertGreaterThan((int) $g[1], (int) $f[1]);
        $this->assertEqualsWithDelta(7, (int) $f[1] - (int) $g[1], 1);
    }

    public function test_pill_still_renders_a_readable_no_js_fallback(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $this->liveOffer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        $html = Livewire::test(Index::class)->html();

        // (The x-text expression contains a `>`, so anchor on its closing quote.)
        $this->assertSame(1, preg_match('/data-seconds="(\d+)"/', $html, $seconds));
        $this->assertSame(1, preg_match('/x-text="[^"]*">\s*(\d+)s left\s*<\/span>/s', $html, $text));
        $this->assertSame($seconds[1], $text[1], 'server-rendered text and data-seconds must agree');
    }

    public function test_countdown_component_reads_data_seconds_and_releases_everything_it_starts(): void
    {
        $js = file_get_contents(resource_path('js/provider-alerts.js'));
        $block = substr($js, strpos($js, "Alpine.data('providerOfferCountdown'"));
        $block = substr($block, 0, strpos($block, "Alpine.data('providerOfferAlert'"));

        // Factory takes no argument; the seconds come from the element.
        $this->assertStringContainsString("Alpine.data('providerOfferCountdown', () =>", $block);
        $this->assertStringContainsString('el.dataset.seconds', $block);
        $this->assertStringContainsString("attributeFilter: ['data-seconds']", $block);

        // Teardown releases both the interval and the observer.
        $this->assertStringContainsString('destroy: stop', $block);
        $this->assertStringContainsString('window.clearInterval(timer)', $block);
        $this->assertStringContainsString('observer.disconnect()', $block);

        // No inline-interval regression of the original bug.
        $view = file_get_contents(resource_path('views/livewire/provider/jobs/index.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/x-init="[^"]*setInterval/', $view);
    }

    public function test_countdown_behaviour_suite_passes_against_the_real_module(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node is not installed; run tests/js/provider-offer-countdown.test.mjs where it is.');
        }

        $process = new Process([$node, '--test', base_path('tests/js/provider-offer-countdown.test.mjs')], base_path(), null, null, 120);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "Countdown behaviour suite failed:\n".$process->getOutput().$process->getErrorOutput(),
        );
    }
}

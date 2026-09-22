<?php

namespace Tests\Feature\ProviderWeb;

use App\Livewire\Provider\Dashboard;
use App\Livewire\Provider\OnlineToggle;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Provider location-heartbeat interval leak (1CF-HEARTBEAT-LEAK-FIX-001).
 *
 * The heartbeat used to be an inline `x-init="setInterval(...)"` in
 * online-toggle.blade.php (mounted TWICE by the layout: header chip + drawer
 * copy) and again in dashboard.blade.php. Nothing ever cleared it, so every
 * render, wire:navigate and — worst — going offline left an interval alive
 * that kept calling `$wire.goOnline`, quietly putting the provider back online.
 *
 * The interval now lives in the `providerHeartbeat` Alpine component
 * (resources/js/provider-alerts.js). What can be pinned without a browser lives
 * here:
 *
 *   - the views carry only the marker, never an inline interval;
 *   - the marker exists only while the provider is online (so leaving that
 *     state removes it, which is what makes Alpine stop the heartbeat);
 *   - the sibling components stay in step through one Livewire event, so a
 *     stale twin cannot keep a heartbeat running after the provider went
 *     offline in another copy;
 *   - the goOnline()/goOffline() contract is unchanged;
 *   - the interval behaviour itself is asserted against the real module by
 *     tests/js/provider-heartbeat.test.mjs, which this class runs.
 */
class ProviderHeartbeatLeakTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private const MARKER = 'x-data="providerHeartbeat"';

    private function provider(bool $online): Provider
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        $provider->forceFill(['is_online' => $online])->save();
        $this->actingAs($provider->user);

        return $provider->fresh();
    }

    public function test_no_provider_view_carries_an_inline_interval(): void
    {
        $files = [
            resource_path('views/livewire/provider/online-toggle.blade.php'),
            resource_path('views/livewire/provider/dashboard.blade.php'),
            resource_path('views/components/layouts/provider.blade.php'),
        ];

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/setInterval|x-init="[^"]*getCurrentPosition/s',
                file_get_contents($file),
                basename($file).' must not own a heartbeat interval; providerHeartbeat does',
            );
        }
    }

    public function test_the_toggle_renders_one_marker_only_while_online(): void
    {
        $this->provider(true);

        $html = Livewire::test(OnlineToggle::class)->html();
        $this->assertSame(1, substr_count($html, self::MARKER));
        $this->assertStringNotContainsString('setInterval', $html);

        $this->provider(false);
        $offline = Livewire::test(OnlineToggle::class)->html();
        $this->assertSame(0, substr_count($offline, self::MARKER), 'an offline provider must not render a heartbeat');
        $this->assertStringContainsString('Go online', $offline);
    }

    public function test_the_dashboard_card_renders_one_marker_only_while_online(): void
    {
        $this->provider(true);
        $this->assertSame(1, substr_count(Livewire::test(Dashboard::class)->html(), self::MARKER));

        $this->provider(false);
        $this->assertSame(0, substr_count(Livewire::test(Dashboard::class)->html(), self::MARKER));
    }

    public function test_going_offline_removes_the_marker_on_the_same_render(): void
    {
        $this->provider(true);

        $toggle = Livewire::test(OnlineToggle::class);
        $this->assertSame(1, substr_count($toggle->html(), self::MARKER));

        $toggle->call('goOffline');
        $this->assertSame(0, substr_count($toggle->html(), self::MARKER));

        $dashboard = Livewire::test(Dashboard::class)->call('goOffline');
        $this->assertSame(0, substr_count($dashboard->html(), self::MARKER));
    }

    public function test_the_full_layout_still_mounts_the_toggle_twice_and_the_dashboard_once(): void
    {
        // Documents why the interval has to be page-wide: three markers exist on
        // the Dashboard (header chip, drawer copy, Dashboard card).
        $provider = $this->provider(true);

        $html = $this->actingAs($provider->user)->get(route('provider.dashboard'))->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(3, substr_count($html, 'providerHeartbeat'));
        $this->assertStringNotContainsString('setInterval(', $html);
    }

    public function test_a_stale_twin_re_renders_offline_when_another_copy_goes_offline(): void
    {
        $provider = $this->provider(true);

        $chip = Livewire::test(OnlineToggle::class);
        $drawerTwin = Livewire::test(OnlineToggle::class);
        $this->assertSame(1, substr_count($drawerTwin->html(), self::MARKER));

        // The chip goes offline...
        $chip->call('goOffline')->assertDispatched('provider-availability-changed');
        $this->assertFalse((bool) $provider->fresh()->is_online);

        // ...and the twin, receiving that event, must drop its heartbeat marker.
        $drawerTwin->call('syncAvailability');
        $this->assertSame(0, substr_count($drawerTwin->html(), self::MARKER));
        $this->assertStringContainsString('Go online', $drawerTwin->html());
    }

    public function test_the_dashboard_card_and_the_toggle_listen_for_the_same_availability_event(): void
    {
        $provider = $this->provider(true);

        $card = Livewire::test(Dashboard::class);
        $toggle = Livewire::test(OnlineToggle::class);
        $toggle->call('goOffline');

        $card->call('syncAvailability');
        $this->assertSame(0, substr_count($card->html(), self::MARKER));

        // And the other direction: the card going online brings the toggle back.
        $card->call('goOnline', 12.97, 77.59)->assertDispatched('provider-availability-changed');
        $toggle->call('syncAvailability');
        $this->assertSame(1, substr_count($toggle->html(), self::MARKER));
        $this->assertTrue((bool) $provider->fresh()->is_online);
    }

    public function test_the_heartbeat_repeat_call_while_already_online_does_not_fan_out(): void
    {
        $provider = $this->provider(true);

        // The 2-minute heartbeat calls goOnline again with the same online status:
        // it must still re-stamp the location, and must NOT re-render every sibling.
        Livewire::test(OnlineToggle::class)
            ->call('goOnline', 12.9, 77.6)
            ->assertNotDispatched('provider-availability-changed');
        Livewire::test(Dashboard::class)
            ->call('goOnline', 13.0, 77.7)
            ->assertNotDispatched('provider-availability-changed');

        $fresh = $provider->fresh();
        $this->assertTrue((bool) $fresh->is_online);
        $this->assertEqualsWithDelta(13.0, (float) $fresh->current_lat, 0.0001);
        $this->assertNotNull($fresh->location_updated_at);
    }

    public function test_an_offline_to_online_flip_announces_itself_and_records_the_fix(): void
    {
        $provider = $this->provider(false);

        Livewire::test(OnlineToggle::class)
            ->call('goOnline', 12.9716, 77.5946)
            ->assertDispatched('provider-availability-changed');

        $fresh = $provider->fresh();
        $this->assertTrue((bool) $fresh->is_online);
        $this->assertEqualsWithDelta(12.9716, (float) $fresh->current_lat, 0.0001);
        $this->assertEqualsWithDelta(77.5946, (float) $fresh->current_lng, 0.0001);
    }

    public function test_going_online_without_a_fix_still_succeeds_and_keeps_the_existing_contract(): void
    {
        $provider = $this->provider(false);

        Livewire::test(OnlineToggle::class)->call('goOnline', null, null)->assertHasNoErrors();

        $this->assertTrue((bool) $provider->fresh()->is_online);
    }

    public function test_the_heartbeat_component_owns_one_interval_and_a_destroy_that_releases_it(): void
    {
        $js = file_get_contents(resource_path('js/provider-alerts.js'));

        $this->assertStringContainsString("Alpine.data('providerHeartbeat', () =>", $js);
        $this->assertStringContainsString('heartbeatJoin(member)', $js);
        $this->assertStringContainsString('heartbeatLeave(member)', $js);
        $this->assertStringContainsString('window.clearInterval(heartbeatTimer)', $js);
        $this->assertStringContainsString('const HEARTBEAT_MS = 120000;', $js, 'heartbeat cadence unchanged');

        // Exactly one place creates the heartbeat interval.
        $this->assertSame(1, substr_count($js, 'window.setInterval(heartbeatTick'));
    }

    public function test_heartbeat_behaviour_suite_passes_against_the_real_module(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node is not installed; run tests/js/provider-heartbeat.test.mjs where it is.');
        }

        $process = new Process([$node, '--test', base_path('tests/js/provider-heartbeat.test.mjs')], base_path(), null, null, 120);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "Heartbeat behaviour suite failed:\n".$process->getOutput().$process->getErrorOutput(),
        );
    }
}

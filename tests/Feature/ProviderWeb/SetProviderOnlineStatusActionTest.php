<?php

namespace Tests\Feature\ProviderWeb;

use App\Actions\SetProviderOnlineStatusAction;
use App\Models\ActivityLog;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * PHASE PW1 §3.1 — SetProviderOnlineStatusAction in isolation.
 *
 * The Action is the only new business-logic class in the Provider Web P1
 * build; these cover its full contract without routes, middleware or a
 * logged-in session (the causer is just whatever `auth()->user()` returns).
 */
class SetProviderOnlineStatusActionTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function provider(): Provider
    {
        [, , $franchise, $zone] = $this->makeFranchiseTree();
        $provider = $this->makeProviderIn($franchise, $zone);
        // Start from a clean slate: offline, no fix.
        $provider->forceFill(['is_online' => false, 'current_lat' => null, 'current_lng' => null, 'location_updated_at' => null])->save();

        return $provider->fresh();
    }

    private function action(): SetProviderOnlineStatusAction
    {
        return app(SetProviderOnlineStatusAction::class);
    }

    public function test_going_online_with_a_location_fix_sets_all_four_columns_and_stamps_location_updated_at(): void
    {
        $provider = $this->provider();
        $before = now()->subSecond();

        $result = $this->action()->execute($provider, true, 12.9716, 77.5946);

        $this->assertTrue($result->is_online);
        $this->assertSame(12.9716, (float) $result->current_lat);
        $this->assertSame(77.5946, (float) $result->current_lng);
        $this->assertNotNull($result->location_updated_at);
        $this->assertTrue($result->location_updated_at->greaterThanOrEqualTo($before));

        $this->assertDatabaseHas('providers', [
            'id' => $provider->id, 'is_online' => 1, 'current_lat' => 12.9716, 'current_lng' => 77.5946,
        ]);
    }

    public function test_going_online_without_coordinates_sets_is_online_only_and_leaves_location_null(): void
    {
        $provider = $this->provider();

        $result = $this->action()->execute($provider, true);

        $this->assertTrue($result->is_online);
        $this->assertNull($result->current_lat);
        $this->assertNull($result->current_lng);
        $this->assertNull($result->location_updated_at, 'No fix supplied — nothing new stamped.');
    }

    public function test_going_online_with_partial_coordinates_is_treated_as_no_fix(): void
    {
        $provider = $this->provider();

        $result = $this->action()->execute($provider, true, 12.9716, null);

        $this->assertTrue($result->is_online);
        $this->assertNull($result->current_lat);
        $this->assertNull($result->location_updated_at);
    }

    public function test_going_online_with_out_of_range_coordinates_is_treated_as_no_fix(): void
    {
        $provider = $this->provider();

        $result = $this->action()->execute($provider, true, 999.0, 12.0);

        $this->assertTrue($result->is_online);
        $this->assertNull($result->current_lat, 'An impossible latitude is dropped, not stored.');
        $this->assertNull($result->location_updated_at);
    }

    public function test_going_offline_flips_the_flag_and_leaves_last_known_coordinates_intact(): void
    {
        $provider = $this->provider();
        $this->action()->execute($provider, true, 12.9716, 77.5946);
        $stampedAt = $provider->fresh()->location_updated_at;

        Carbon::setTestNow(now()->addMinutes(10));
        $result = $this->action()->execute($provider->fresh(), false);
        Carbon::setTestNow();

        $this->assertFalse($result->is_online);
        $this->assertSame(12.9716, (float) $result->current_lat, 'Last known position is retained after going offline.');
        $this->assertSame(77.5946, (float) $result->current_lng);
        $this->assertEquals(
            $stampedAt->timestamp,
            $result->location_updated_at->timestamp,
            'Going offline stamps nothing new.'
        );
    }

    public function test_it_writes_an_activity_log_row_with_the_acting_user_as_causer(): void
    {
        $provider = $this->provider();
        $this->actingAs($provider->user);

        $this->action()->execute($provider, true, 12.9716, 77.5946);
        $this->action()->execute($provider->fresh(), false);

        $online = ActivityLog::where('subject_type', Provider::class)->where('subject_id', $provider->id)
            ->where('description', 'Went online (provider web)')->first();
        $offline = ActivityLog::where('subject_type', Provider::class)->where('subject_id', $provider->id)
            ->where('description', 'Went offline (provider web)')->first();

        $this->assertNotNull($online);
        $this->assertSame($provider->user_id, $online->causer_id);
        $this->assertSame(['lat' => 12.9716, 'lng' => 77.5946], $online->properties);

        $this->assertNotNull($offline);
        $this->assertSame([], $offline->properties, 'Offline row carries no coordinates.');
    }

    public function test_it_is_safe_without_an_authenticated_user(): void
    {
        $provider = $this->provider();

        $result = $this->action()->execute($provider, true, 12.9716, 77.5946);

        $this->assertTrue($result->is_online);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Provider::class, 'subject_id' => $provider->id,
            'description' => 'Went online (provider web)', 'causer_id' => null,
        ]);
    }

    public function test_repeated_online_calls_refresh_the_location_timestamp_and_coordinates(): void
    {
        $provider = $this->provider();

        $this->action()->execute($provider, true, 12.9716, 77.5946);
        $first = $provider->fresh()->location_updated_at;

        Carbon::setTestNow(now()->addMinutes(5));
        $result = $this->action()->execute($provider->fresh(), true, 13.0000, 77.6000);
        Carbon::setTestNow();

        $this->assertSame(13.0, (float) $result->current_lat);
        $this->assertSame(77.6, (float) $result->current_lng);
        $this->assertTrue(
            $result->location_updated_at->greaterThan($first),
            'The heartbeat re-stamps location_updated_at.'
        );
    }
}

<?php

namespace Tests\Feature\Operations;

use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Setting;
use App\Services\Operations\ProviderAnomalyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Rbac\RbacTestHelpers;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Admin Polish + AI session, Part 2 item 1 — real regression coverage for
 * the detection LOGIC itself (thresholds, query correctness), per the
 * mission's own instruction that this is the part that must be reliably
 * correct, distinct from any natural-language phrasing layer.
 */
class ProviderAnomalyServiceTest extends TestCase
{
    use RefreshDatabase;
    use RbacTestHelpers;
    use BookingFixtureHelpers;

    private function extraBooking(array $scenario, string $status): Booking
    {
        return Booking::create([
            'code' => 'TST-'.now()->format('dm').'-'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'franchise_id' => $scenario['franchise']->id, 'zone_id' => $scenario['zone']->id,
            'customer_id' => $scenario['customer']->id, 'provider_id' => $scenario['provider']->id,
            'service_id' => $scenario['service']->id, 'address_id' => $scenario['address']->id,
            'status' => $status, 'price_quoted' => 500, 'payment_status' => 'pending', 'payment_method' => 'online',
        ]);
    }

    private function offer(array $scenario, string $status, \DateTimeInterface $notifiedAt): DispatchAttempt
    {
        $booking = $this->extraBooking($scenario, 'searching_provider');

        return DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $scenario['provider']->id,
            'status' => $status, 'notified_at' => $notifiedAt, 'responded_at' => $status !== 'notified' ? $notifiedAt : null,
        ]);
    }

    public function test_flags_a_provider_whose_non_response_rate_today_far_exceeds_their_own_baseline(): void
    {
        $scenario = $this->makeBookingScenario();
        $admin = $this->makeSuperAdmin();

        // Baseline: 10 offers over the trailing window, 1 timeout (10%).
        for ($i = 0; $i < 9; $i++) {
            $this->offer($scenario, 'accepted', now()->subDays(5));
        }
        $this->offer($scenario, 'timeout', now()->subDays(5));

        // Today: 5 offers, 4 timeouts (80%) — well past the default 2x multiplier.
        for ($i = 0; $i < 4; $i++) {
            $this->offer($scenario, 'timeout', now());
        }
        $this->offer($scenario, 'accepted', now());

        $result = app(ProviderAnomalyService::class)->detect($admin);

        $flagged = $result->firstWhere('metric', 'non_response_rate');
        $this->assertNotNull($flagged, 'Expected the provider to be flagged for non-response rate.');
        $this->assertSame($scenario['provider']->id, $flagged['provider']->id);
        $this->assertEqualsWithDelta(80.0, $flagged['today_rate'], 0.1);
    }

    public function test_does_not_flag_a_provider_within_normal_variance_of_their_own_baseline(): void
    {
        $scenario = $this->makeBookingScenario();
        $admin = $this->makeSuperAdmin();

        // Baseline and today both ~20% — not an anomaly.
        for ($i = 0; $i < 8; $i++) {
            $this->offer($scenario, 'accepted', now()->subDays(5));
        }
        for ($i = 0; $i < 2; $i++) {
            $this->offer($scenario, 'timeout', now()->subDays(5));
        }
        for ($i = 0; $i < 4; $i++) {
            $this->offer($scenario, 'accepted', now());
        }
        $this->offer($scenario, 'timeout', now());

        $result = app(ProviderAnomalyService::class)->detect($admin);

        $this->assertNull($result->firstWhere('metric', 'non_response_rate'));
    }

    public function test_does_not_flag_off_a_tiny_sample_below_the_minimum(): void
    {
        $scenario = $this->makeBookingScenario();
        $admin = $this->makeSuperAdmin();

        // Baseline sufficient, but today only 1 offer and it timed out
        // (100%!) — must NOT be flagged, minimum sample size protects
        // against exactly this kind of single-data-point noise.
        for ($i = 0; $i < 9; $i++) {
            $this->offer($scenario, 'accepted', now()->subDays(5));
        }
        $this->offer($scenario, 'timeout', now()->subDays(5));
        $this->offer($scenario, 'timeout', now());

        $result = app(ProviderAnomalyService::class)->detect($admin);

        $this->assertNull($result->firstWhere('metric', 'non_response_rate'));
    }

    public function test_flags_a_provider_cancelling_bookings_far_above_their_baseline(): void
    {
        $scenario = $this->makeBookingScenario();
        $admin = $this->makeSuperAdmin();

        for ($i = 0; $i < 9; $i++) {
            $b = $this->extraBooking($scenario, 'completed');
            $b->forceFill(['created_at' => now()->subDays(5)])->save();
        }
        $b = $this->extraBooking($scenario, 'cancelled');
        $b->forceFill(['created_at' => now()->subDays(5)])->save();

        for ($i = 0; $i < 1; $i++) {
            $b = $this->extraBooking($scenario, 'completed');
            $b->forceFill(['created_at' => now()])->save();
        }
        for ($i = 0; $i < 4; $i++) {
            $b = $this->extraBooking($scenario, 'cancelled');
            $b->forceFill(['created_at' => now()])->save();
        }

        $result = app(ProviderAnomalyService::class)->detect($admin);

        $flagged = $result->firstWhere('metric', 'cancellation_rate');
        $this->assertNotNull($flagged, 'Expected the provider to be flagged for cancellation rate.');
    }

    public function test_threshold_config_is_setting_driven_not_hardcoded(): void
    {
        $scenario = $this->makeBookingScenario();
        $admin = $this->makeSuperAdmin();

        // A rate only 1.2x baseline — under the DEFAULT 2x multiplier, so
        // not flagged by default...
        for ($i = 0; $i < 8; $i++) {
            $this->offer($scenario, 'accepted', now()->subDays(5));
        }
        for ($i = 0; $i < 2; $i++) {
            $this->offer($scenario, 'timeout', now()->subDays(5));
        }
        for ($i = 0; $i < 6; $i++) {
            $this->offer($scenario, 'accepted', now());
        }
        for ($i = 0; $i < 3; $i++) {
            $this->offer($scenario, 'timeout', now());
        }

        $this->assertNull(app(ProviderAnomalyService::class)->detect($admin)->firstWhere('metric', 'non_response_rate'));

        // ...but IS flagged once an admin lowers the configured multiplier —
        // proves the threshold is read from Setting, not a hardcoded constant.
        Setting::set('operations.anomaly.multiplier', '1.1');

        $this->assertNotNull(app(ProviderAnomalyService::class)->detect($admin)->firstWhere('metric', 'non_response_rate'));
    }

    public function test_a_franchise_scoped_actor_never_sees_another_franchises_provider_anomaly(): void
    {
        $scenarioOutside = $this->makeBookingScenario();
        for ($i = 0; $i < 9; $i++) {
            $this->offer($scenarioOutside, 'accepted', now()->subDays(5));
        }
        for ($i = 0; $i < 4; $i++) {
            $this->offer($scenarioOutside, 'timeout', now());
        }

        $scenarioInside = $this->makeBookingScenario();
        $actor = $this->makeUserWithPermission('operations.view', 'franchise', $scenarioInside['franchise']->id);

        $result = app(ProviderAnomalyService::class)->detect($actor);

        $this->assertNull($result->firstWhere('provider.id', $scenarioOutside['provider']->id), 'A franchise-scoped actor must never see another franchise\'s provider in the anomaly list.');
    }
}

<?php

namespace Tests\Feature\Dispatch;

use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Services\ProviderAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * Phase E4 — the half-open `[start, end)` interval logic of
 * ProviderAvailabilityService::isAvailableAt(), against real DB rows.
 * `end = scheduled_at + services.duration_estimate_mins`; two bookings
 * conflict iff `existing.start < requested.end && requested.start < existing.end`.
 */
class ProviderAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;
    use BundleConsolidationHelpers;

    private ProviderAvailabilityService $service;
    private array $ctx;
    private Provider $provider;
    private Service $svc60;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProviderAvailabilityService::class);
        $this->ctx = $this->makeWorld();
        $this->svc60 = $this->makeService($this->ctx['category'], 60);
        $this->provider = $this->makeSkilledProvider($this->ctx['franchise'], $this->ctx['zone'], $this->ctx['category']->id);
    }

    /** A confirmed booking held by the provider from $start for $durationMins. */
    private function blocking(string $start, int $durationMins = 60, string $status = 'assigned'): Booking
    {
        $service = $durationMins === 60 ? $this->svc60 : $this->makeService($this->ctx['category'], $durationMins);

        return $this->makeScheduledBooking($this->ctx, $service, $start, $status, [
            'provider_id' => $this->provider->id,
        ]);
    }

    private function available(string $start, int $durationMins = 60, ?int $exclude = null): bool
    {
        return $this->service->isAvailableAt($this->provider, Carbon::parse($start), $durationMins, $exclude);
    }

    public function test_empty_calendar_is_available(): void
    {
        $this->assertTrue($this->available('2030-01-01 10:00:00'));
    }

    public function test_overlapping_request_is_not_available(): void
    {
        $this->blocking('2030-01-01 10:00:00'); // 10:00–11:00
        $this->assertFalse($this->available('2030-01-01 10:30:00')); // 10:30–11:30
    }

    public function test_adjacent_request_after_existing_is_available(): void
    {
        $this->blocking('2030-01-01 10:00:00'); // 10:00–11:00
        $this->assertTrue($this->available('2030-01-01 11:00:00')); // 11:00–12:00
    }

    public function test_request_ending_exactly_when_existing_starts_is_available(): void
    {
        $this->blocking('2030-01-01 11:00:00'); // 11:00–12:00
        $this->assertTrue($this->available('2030-01-01 10:00:00')); // 10:00–11:00
    }

    public function test_gap_between_two_bookings_is_available(): void
    {
        $this->blocking('2030-01-01 10:00:00'); // 10:00–11:00
        $this->blocking('2030-01-01 12:00:00'); // 12:00–13:00
        $this->assertTrue($this->available('2030-01-01 11:00:00', 30)); // 11:00–11:30
    }

    public function test_request_fully_containing_existing_is_not_available(): void
    {
        $this->blocking('2030-01-01 10:15:00', 15); // 10:15–10:30
        $this->assertFalse($this->available('2030-01-01 10:00:00', 60)); // 10:00–11:00
    }

    public function test_request_fully_inside_existing_is_not_available(): void
    {
        $this->blocking('2030-01-01 10:00:00', 120); // 10:00–12:00
        $this->assertFalse($this->available('2030-01-01 10:30:00', 15)); // 10:30–10:45
    }

    public function test_pending_and_searching_bookings_do_not_block(): void
    {
        $this->blocking('2030-01-01 10:00:00', 60, 'pending');
        $this->blocking('2030-01-01 10:00:00', 60, 'searching_provider');
        $this->assertTrue($this->available('2030-01-01 10:00:00'));
    }

    public function test_assigned_booking_blocks(): void
    {
        $this->blocking('2030-01-01 10:00:00', 60, 'assigned');
        $this->assertFalse($this->available('2030-01-01 10:30:00'));
    }

    public function test_provider_en_route_in_progress_and_on_hold_block(): void
    {
        foreach (['provider_en_route', 'in_progress', 'on_hold'] as $status) {
            $booking = $this->blocking('2030-02-01 10:00:00', 60, $status);
            $this->assertFalse($this->available('2030-02-01 10:30:00'), "{$status} must block");
            $booking->delete();
        }
    }

    public function test_completed_and_cancelled_bookings_do_not_block(): void
    {
        $this->blocking('2030-01-01 10:00:00', 60, 'completed');
        $this->blocking('2030-01-01 10:00:00', 60, 'cancelled');
        $this->assertTrue($this->available('2030-01-01 10:00:00'));
    }

    public function test_a_booking_can_be_excluded_from_its_own_check(): void
    {
        $booking = $this->blocking('2030-01-01 10:00:00', 60, 'assigned');
        $this->assertFalse($this->available('2030-01-01 10:00:00'));
        $this->assertTrue($this->available('2030-01-01 10:00:00', 60, $booking->id));
    }

    public function test_other_providers_bookings_are_ignored(): void
    {
        $other = $this->makeSkilledProvider($this->ctx['franchise'], $this->ctx['zone'], $this->ctx['category']->id);
        $this->makeScheduledBooking($this->ctx, $this->svc60, '2030-01-01 10:00:00', 'assigned', [
            'provider_id' => $other->id,
        ]);
        $this->assertTrue($this->available('2030-01-01 10:00:00'));
    }

    public function test_booking_with_null_scheduled_at_is_ignored(): void
    {
        $this->makeScheduledBooking($this->ctx, $this->svc60, null, 'assigned', [
            'provider_id' => $this->provider->id,
        ]);
        $this->assertTrue($this->available('2030-01-01 10:00:00'));
    }
}

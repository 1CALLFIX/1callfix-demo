<?php

namespace Tests\Feature\Dispatch;

use App\Actions\AcceptBookingAction;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BundleConsolidationHelpers;
use Tests\TestCase;

/**
 * Phase E4 — the acceptance-time race guard. A pre-dispatch availability
 * check is not enough: two offers for overlapping windows can both reach the
 * same free provider (a bundle consolidation offer, or a plain accept/accept
 * race). AcceptBookingAction now locks the provider row FOR UPDATE inside
 * its assignment transaction and re-checks ProviderAvailabilityService
 * before writing provider_id — so once one overlapping booking is assigned,
 * the second acceptance is rejected and left for the existing dispatch
 * requeue, never committed on top.
 *
 * PHPUnit is single-threaded (same honest limitation
 * ServiceMatchingJobRaceTest / RentalAvailabilityConcurrencyTest document):
 * this proves the serialized OUTCOME the row lock guarantees — accept A to
 * completion, then accept B — which is exactly what a real race resolves to.
 */
class AcceptBookingAvailabilityConcurrencyTest extends TestCase
{
    use RefreshDatabase;
    use BundleConsolidationHelpers;

    public function test_second_overlapping_acceptance_for_the_same_provider_is_rejected(): void
    {
        $ctx = $this->makeWorld();
        $service = $this->makeService($ctx['category'], 60);
        $provider = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id);

        $a = $this->makeScheduledBooking($ctx, $service, '2030-05-01 10:00:00'); // 10:00–11:00
        $b = $this->makeScheduledBooking($ctx, $service, '2030-05-01 10:30:00'); // 10:30–11:30
        $this->offer($a, $provider);
        $this->offer($b, $provider);

        $accepted = app(AcceptBookingAction::class)->execute($a->id, $provider);
        $this->assertSame('assigned', $accepted->status);
        $this->assertSame($provider->id, $accepted->provider_id);

        try {
            app(AcceptBookingAction::class)->execute($b->id, $provider);
            $this->fail('Expected the overlapping second acceptance to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('overlaps', $e->getMessage());
        }

        $b->refresh();
        $this->assertNull($b->provider_id, 'The losing booking must not be assigned to the provider.');
        $this->assertSame('searching_provider', $b->status, 'The losing booking stays in its prior searching state.');
        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $b->id, 'provider_id' => $provider->id, 'status' => 'notified',
        ]);
    }

    public function test_two_non_overlapping_bookings_can_both_be_assigned_to_one_provider(): void
    {
        $ctx = $this->makeWorld();
        $service = $this->makeService($ctx['category'], 60);
        $provider = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id);

        $a = $this->makeScheduledBooking($ctx, $service, '2030-05-02 10:00:00'); // 10:00–11:00
        $b = $this->makeScheduledBooking($ctx, $service, '2030-05-02 11:00:00'); // 11:00–12:00 (adjacent)
        $this->offer($a, $provider);
        $this->offer($b, $provider);

        $acceptedA = app(AcceptBookingAction::class)->execute($a->id, $provider);
        $acceptedB = app(AcceptBookingAction::class)->execute($b->id, $provider);

        $this->assertSame('assigned', $acceptedA->status);
        $this->assertSame('assigned', $acceptedB->status);
        $this->assertSame($provider->id, $acceptedA->provider_id);
        $this->assertSame($provider->id, $acceptedB->provider_id);
        $this->assertSame(2, Booking::where('provider_id', $provider->id)->where('status', 'assigned')->count());
    }

    public function test_booking_without_a_schedule_skips_the_guard(): void
    {
        $ctx = $this->makeWorld();
        $service = $this->makeService($ctx['category'], 60);
        $provider = $this->makeSkilledProvider($ctx['franchise'], $ctx['zone'], $ctx['category']->id);

        $a = $this->makeScheduledBooking($ctx, $service, '2030-05-03 10:00:00');
        $b = $this->makeScheduledBooking($ctx, $service, null); // no scheduled_at
        $this->offer($a, $provider);
        $this->offer($b, $provider);

        app(AcceptBookingAction::class)->execute($a->id, $provider);
        $accepted = app(AcceptBookingAction::class)->execute($b->id, $provider);

        $this->assertSame('assigned', $accepted->status, 'An unscheduled booking cannot be interval-checked and is accepted as before E4.');
    }
}

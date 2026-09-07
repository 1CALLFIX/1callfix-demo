<?php

namespace Tests\Feature\ProviderWeb;

use App\Actions\AdminCancelBookingAction;
use App\Events\BookingStatusUpdated;
use App\Livewire\Provider\Jobs\Show;
use App\Models\BookingStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase PN1 — the new `provider_en_route` transition (MarkEnRouteAction +
 * the "I'm on my way" button on the job screen). The FSM already carried
 * `provider_en_route` in its enum and in every completable-/holdable-/
 * startable-from set; this proves the transition INTO it works and that the
 * existing accept → start → complete lifecycle still runs with an en-route
 * step inserted.
 */
class ProviderEnRouteLifecycleTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    public function test_provider_marks_en_route_from_the_job_screen(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $this->actingAs($s['provider']->user);

        Livewire::test(Show::class, ['booking' => $s['booking']])
            ->call('enRoute')
            ->assertSet('notice', 'Marked on the way.')
            ->assertHasNoErrors();

        $s['booking']->refresh();
        $this->assertSame('provider_en_route', $s['booking']->status);
        $this->assertDatabaseHas('booking_status_history', [
            'booking_id' => $s['booking']->id,
            'status' => 'provider_en_route',
        ]);
    }

    public function test_marking_en_route_fires_the_booking_status_updated_event(): void
    {
        Event::fake([BookingStatusUpdated::class]);
        $s = $this->makeAssignedBookingScenario();
        $this->actingAs($s['provider']->user);

        Livewire::test(Show::class, ['booking' => $s['booking']])->call('enRoute');

        Event::assertDispatched(BookingStatusUpdated::class,
            fn (BookingStatusUpdated $e) => $e->booking->id === $s['booking']->id
                && $e->booking->status === 'provider_en_route');
    }

    public function test_full_lifecycle_accept_then_en_route_then_start_then_complete(): void
    {
        $s = $this->makeAssignedBookingScenario(); // already assigned; start 1234 / completion 5678
        $this->actingAs($s['provider']->user);

        Livewire::test(Show::class, ['booking' => $s['booking']])->call('enRoute');
        $this->assertSame('provider_en_route', $s['booking']->fresh()->status);

        Livewire::test(Show::class, ['booking' => $s['booking']->fresh()])
            ->set('otp', '1234')->call('start')
            ->assertSet('notice', 'Job started.');
        $this->assertSame('in_progress', $s['booking']->fresh()->status);

        Livewire::test(Show::class, ['booking' => $s['booking']->fresh()])
            ->set('otp', '5678')->call('complete')
            ->assertSet('notice', 'Job completed.');

        $s['booking']->refresh();
        $this->assertSame('completed', $s['booking']->status);
        $this->assertDatabaseHas('commissions', ['booking_id' => $s['booking']->id]);

        // The timeline kept every step, in order.
        $statuses = BookingStatusHistory::where('booking_id', $s['booking']->id)
            ->orderBy('changed_at')->orderBy('id')->pluck('status')->all();
        foreach (['provider_en_route', 'in_progress', 'completed'] as $expected) {
            $this->assertContains($expected, $statuses);
        }
    }

    public function test_start_still_works_without_an_en_route_step(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $this->actingAs($s['provider']->user);

        Livewire::test(Show::class, ['booking' => $s['booking']])
            ->set('otp', '1234')->call('start')
            ->assertSet('notice', 'Job started.');

        $this->assertSame('in_progress', $s['booking']->fresh()->status);
    }

    public function test_cannot_mark_en_route_a_job_that_is_not_yours(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $other = $this->makeProviderIn($s['franchise'], $s['zone']);
        $this->actingAs($other->user);

        $this->get(route('provider.jobs.show', $s['booking']))->assertNotFound();
    }

    public function test_cannot_mark_en_route_from_in_progress(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $s['booking']->update(['status' => 'in_progress']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Show::class, ['booking' => $s['booking']->fresh()])
            ->call('enRoute')
            ->assertSet('error', "Booking [{$s['booking']->id}] cannot be marked en route from status 'in_progress'.");

        $this->assertSame('in_progress', $s['booking']->fresh()->status);
    }

    public function test_en_route_job_is_still_completable_and_cancellable(): void
    {
        // provider_en_route is in CompleteBookingAction's and
        // AdminCancelBookingAction's accepted-from sets — a quick guard that
        // inserting the step doesn't strand a job.
        $s = $this->makeAssignedBookingScenario();
        $this->actingAs($s['provider']->user);
        Livewire::test(Show::class, ['booking' => $s['booking']])->call('enRoute');

        app(AdminCancelBookingAction::class)->execute($s['booking']->id, 'customer no-show');

        $this->assertSame('cancelled', $s['booking']->fresh()->status);
    }
}

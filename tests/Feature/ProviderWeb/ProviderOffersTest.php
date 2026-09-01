<?php

namespace Tests\Feature\ProviderWeb;

use App\Livewire\Provider\Jobs\Index;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * PHASE PW1 §4 — the offers list (windowed by dispatch.offer_timeout_seconds),
 * Accept via AcceptBookingAction, and Decline as a single guarded status
 * write that DispatchService already honours as a permanent exclusion.
 */
class ProviderOffersTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    /** Scenario where the provider is a genuine dispatch candidate for the booking. */
    private function offerScenario(): array
    {
        $s = $this->makeBookingScenario('searching_provider');
        // Real candidate: coords near the address (1.0,1.0), the right skill.
        $s['provider']->update([
            'current_lat' => 1.001, 'current_lng' => 1.001,
            'skills' => [$s['service']->category_id],
        ]);
        $s['provider'] = $s['provider']->fresh();

        return $s;
    }

    private function offer(Booking $booking, Provider $provider, ?\DateTimeInterface $notifiedAt = null): DispatchAttempt
    {
        return DispatchAttempt::create([
            'booking_id' => $booking->id,
            'provider_id' => $provider->id,
            'status' => 'notified',
            'distance_km' => 1.2,
            'notified_at' => $notifiedAt ?? now(),
        ]);
    }

    public function test_a_live_offer_is_listed(): void
    {
        $s = $this->offerScenario();
        $this->offer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)
            ->assertSee($s['booking']->code)
            ->assertSee($s['service']->name);
    }

    public function test_a_stale_offer_past_the_window_is_not_listed(): void
    {
        $s = $this->offerScenario();
        // Default window is 25s (no settings row in tests).
        $this->offer($s['booking'], $s['provider'], now()->subSeconds(120));
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)->assertDontSee($s['booking']->code);
    }

    public function test_accept_assigns_the_booking_and_redirects_to_the_job(): void
    {
        $s = $this->offerScenario();
        $this->offer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)
            ->call('accept', $s['booking']->id)
            ->assertRedirect(route('provider.jobs.show', $s['booking']->id));

        $s['booking']->refresh();
        $this->assertSame('assigned', $s['booking']->status);
        $this->assertSame($s['provider']->id, $s['booking']->provider_id);
        $this->assertNotEmpty($s['booking']->start_otp);
    }

    public function test_accepting_an_offer_that_is_gone_shows_a_message(): void
    {
        $s = $this->offerScenario();
        // No dispatch_attempts row at all.
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)
            ->call('accept', $s['booking']->id)
            ->assertSet('error', 'That offer is no longer available.');

        $this->assertNull($s['booking']->fresh()->provider_id);
    }

    public function test_decline_marks_the_attempt_rejected_and_permanently_excludes_only_that_provider(): void
    {
        $s = $this->offerScenario();

        // A control provider in the same zone whose offer merely TIMED OUT —
        // per DispatchService that stays re-eligible, so it isolates that
        // decline (rejected) is the permanent one.
        $control = $this->makeProviderIn($s['franchise'], $s['zone']);
        $control->update(['current_lat' => 1.001, 'current_lng' => 1.001, 'skills' => [$s['service']->category_id]]);
        DispatchAttempt::create([
            'booking_id' => $s['booking']->id, 'provider_id' => $control->id,
            'status' => 'timeout', 'distance_km' => 1.3,
            'notified_at' => now()->subMinutes(5), 'responded_at' => now()->subMinutes(4),
        ]);

        $this->offer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)
            ->call('decline', $s['booking']->id)
            ->assertSet('notice', 'Offer declined.');

        $this->assertDatabaseHas('dispatch_attempts', [
            'booking_id' => $s['booking']->id, 'provider_id' => $s['provider']->id, 'status' => 'rejected',
        ]);

        $candidates = app(DispatchService::class)->findCandidates($s['booking']->fresh(), 5)->pluck('provider.id');
        $this->assertFalse($candidates->contains($s['provider']->id), 'A declined offer permanently excludes the provider for this booking.');
        $this->assertTrue($candidates->contains($control->id), 'A merely timed-out provider stays eligible — decline is the permanent one.');
    }

    public function test_decline_without_a_live_offer_404s(): void
    {
        $s = $this->offerScenario();
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)
            ->call('decline', $s['booking']->id)
            ->assertStatus(404);
    }
}

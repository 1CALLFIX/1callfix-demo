<?php

namespace Tests\Feature\ProviderWeb;

use App\Actions\AdminCancelBookingAction;
use App\Actions\PlaceBookingOnHoldAction;
use App\Livewire\Provider\Dashboard;
use App\Livewire\Provider\Jobs\Index;
use App\Livewire\Provider\Jobs\Show;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * Phase PN1 — foreground alerts. The chime + tab-hidden OS notification are
 * browser-only (Web Audio + the Notifications API in public/js/
 * provider-alerts.js) and are NOT exercised here — PHPUnit can't play audio.
 * What IS tested is the whole server-side trigger surface those depend on:
 * the components dispatch `provider-alert-offers` / `provider-alert-status`
 * browser events with the right payload, on the same `wire:poll` cycle that
 * already drives the provider dashboard. `$refresh` here IS a poll cycle —
 * it re-runs render() exactly as wire:poll does.
 */
class ProviderForegroundAlertsTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function liveOffer(Booking $booking, Provider $provider, ?\DateTimeInterface $at = null): DispatchAttempt
    {
        return DispatchAttempt::create([
            'booking_id' => $booking->id,
            'provider_id' => $provider->id,
            'status' => 'notified',
            'distance_km' => 1.2,
            'notified_at' => $at ?? now(),
        ]);
    }

    public function test_offers_page_dispatches_the_offer_count_for_the_alarm(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $this->liveOffer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)
            ->assertDispatched('provider-alert-offers', fn ($name, $params) => ($params['count'] ?? null) === 1);
    }

    public function test_offers_page_with_no_offers_dispatches_zero(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)
            ->assertDispatched('provider-alert-offers', fn ($name, $params) => ($params['count'] ?? null) === 0);
    }

    public function test_a_new_offer_surfaces_and_re_alarms_within_one_poll_cycle(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $this->actingAs($s['provider']->user);

        $component = Livewire::test(Index::class)->assertDontSee($s['booking']->code);

        // A dispatch round lands between polls.
        $this->liveOffer($s['booking'], $s['provider']);

        $component->call('$refresh')
            ->assertSee($s['booking']->code)
            ->assertDispatched('provider-alert-offers', fn ($name, $params) => ($params['count'] ?? null) === 1);
    }

    public function test_offer_card_renders_the_reused_expiry_countdown(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $this->liveOffer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)
            ->assertSee('s left')
            ->assertSeeHtml('role="timer"');
    }

    public function test_a_stale_offer_past_the_window_drops_the_count_back_to_zero(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $this->liveOffer($s['booking'], $s['provider'], now()->subSeconds(120)); // default window 25s
        $this->actingAs($s['provider']->user);

        Livewire::test(Index::class)
            ->assertDontSee($s['booking']->code)
            ->assertDispatched('provider-alert-offers', fn ($name, $params) => ($params['count'] ?? null) === 0);
    }

    public function test_dashboard_also_dispatches_the_offer_count_and_shows_a_banner(): void
    {
        $s = $this->makeBookingScenario('searching_provider');
        $this->liveOffer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::test(Dashboard::class)
            ->assertSee('new job offer')
            ->assertDispatched('provider-alert-offers', fn ($name, $params) => ($params['count'] ?? null) === 1);
    }

    public function test_job_screen_alerts_on_an_externally_driven_status_change(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $this->actingAs($s['provider']->user);

        $component = Livewire::test(Show::class, ['booking' => $s['booking']]);

        // Dispatcher holds the job from the admin side, between polls.
        app(PlaceBookingOnHoldAction::class)->execute($s['booking']->id, 'awaiting_spares');

        $component->call('$refresh')
            ->assertDispatched('provider-alert-status', fn ($name, $params) => ($params['title'] ?? '') === 'Job on hold');
    }

    public function test_job_screen_does_not_alert_the_provider_for_their_own_action(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $this->actingAs($s['provider']->user);

        Livewire::test(Show::class, ['booking' => $s['booking']])
            ->set('otp', '1234')
            ->call('start')
            ->assertSet('notice', 'Job started.')
            ->assertNotDispatched('provider-alert-status');
    }

    public function test_job_screen_alerts_when_the_job_is_cancelled_out_from_under_the_provider(): void
    {
        $s = $this->makeAssignedBookingScenario();
        $this->actingAs($s['provider']->user);

        $component = Livewire::test(Show::class, ['booking' => $s['booking']]);
        app(AdminCancelBookingAction::class)->execute($s['booking']->id, 'customer no-show');

        $component->call('$refresh')
            ->assertDispatched('provider-alert-status', fn ($name, $params) => ($params['title'] ?? '') === 'Job cancelled');
    }
}

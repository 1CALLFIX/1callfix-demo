<?php

namespace Tests\Feature\ProviderWeb;

use App\Contracts\PushAdapter;
use App\Livewire\Provider\Jobs\Index;
use App\Livewire\Provider\OfferWatcher;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Provider;
use App\Models\Setting;
use App\Notifications\Channels\PushChannel;
use App\Notifications\ProviderJobOfferNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Feature\Support\BookingFixtureHelpers;
use Tests\TestCase;

/**
 * 1CF-FIX-ALERT-002 — a job-offer push must open a page that works.
 *
 * The notification used to link to provider.jobs.show, which 404s for any
 * booking the provider doesn't already hold (`provider_id` is null until they
 * accept) — i.e. for every offer. It now links to the offers page with
 * `?offer={bookingId}`, which answers a live offer, forwards an accepted one
 * to the job page, and explains an expired/unavailable one without a 404 and
 * without saying anything about a booking that isn't the provider's.
 *
 * Also pins the global alert source (OfferWatcher in the shared layout).
 */
class ProviderOfferNotificationRoutingTest extends TestCase
{
    use BookingFixtureHelpers;
    use RefreshDatabase;

    private function offerScenario(): array
    {
        $s = $this->makeBookingScenario('searching_provider');
        $s['provider']->update([
            'current_lat' => 1.001, 'current_lng' => 1.001, 'location_updated_at' => now(),
            'skills' => [$s['service']->category_id], 'is_online' => true,
        ]);
        $s['provider'] = $s['provider']->fresh();

        return $s;
    }

    private function offer(Booking $booking, Provider $provider, ?\DateTimeInterface $notifiedAt = null, string $status = 'notified'): DispatchAttempt
    {
        return DispatchAttempt::create([
            'booking_id' => $booking->id, 'provider_id' => $provider->id,
            'status' => $status, 'distance_km' => 1.2, 'notified_at' => $notifiedAt ?? now(),
        ]);
    }

    private function linkFor(Booking $booking, Provider $provider): string
    {
        return (new ProviderJobOfferNotification($booking, [PushChannel::class]))->pushLink($provider->user);
    }

    /* --------------------------- the generated link --------------------------- */

    public function test_the_link_uses_the_configured_application_host(): void
    {
        $s = $this->offerScenario();

        $this->assertSame(
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url($this->linkFor($s['booking'], $s['provider']), PHP_URL_HOST),
        );
    }

    public function test_no_host_is_hardcoded_the_link_follows_whatever_root_the_app_is_served_from(): void
    {
        $s = $this->offerScenario();

        URL::forceRootUrl('https://partner.example.test');

        $link = $this->linkFor($s['booking'], $s['provider']);

        $this->assertSame('partner.example.test', parse_url($link, PHP_URL_HOST));
        $this->assertSame('/provider/jobs', parse_url($link, PHP_URL_PATH));
    }

    public function test_the_link_resolves_to_the_existing_offers_route_and_keeps_the_offer_id(): void
    {
        $s = $this->offerScenario();
        $link = $this->linkFor($s['booking'], $s['provider']);

        $route = app('router')->getRoutes()->match(Request::create($link));

        $this->assertSame('provider.jobs.index', $route->getName());
        $this->assertSame((string) $s['booking']->id, Request::create($link)->query('offer'));
        $this->assertNull(parse_url($link, PHP_URL_FRAGMENT));
    }

    public function test_the_push_channel_hands_the_same_link_to_the_adapter(): void
    {
        $s = $this->offerScenario();
        $s['provider']->user->update(['fcm_token' => 'provider-web-token']);

        $sent = [];
        $this->app->bind(PushAdapter::class, function () use (&$sent) {
            return new class($sent) implements PushAdapter {
                public function __construct(public array &$sent) {}

                public function send(string $token, string $title, string $body, array $data = []): bool
                {
                    $this->sent[] = $data;

                    return true;
                }
            };
        });

        $notification = new ProviderJobOfferNotification($s['booking'], [PushChannel::class]);
        app(PushChannel::class)->send($s['provider']->user->fresh(), $notification);

        $this->assertCount(1, $sent);
        $this->assertSame($this->linkFor($s['booking'], $s['provider']), $sent[0]['link']);
    }

    /* ------------------------- opening the link, end to end ------------------------- */

    public function test_a_live_offer_link_opens_the_offers_page_and_shows_the_offer(): void
    {
        $s = $this->offerScenario();
        $this->offer($s['booking'], $s['provider']);

        $this->actingAs($s['provider']->user)
            ->get($this->linkFor($s['booking'], $s['provider']))
            ->assertOk()
            ->assertSee($s['booking']->code)
            ->assertDontSee('no longer available');
    }

    public function test_the_old_job_page_link_really_was_a_404_for_an_unaccepted_offer(): void
    {
        // Documents the defect this fix removes, so nobody "simplifies" the
        // link back to provider.jobs.show.
        $s = $this->offerScenario();
        $this->offer($s['booking'], $s['provider']);

        $this->actingAs($s['provider']->user)
            ->get(route('provider.jobs.show', $s['booking']))
            ->assertNotFound();
    }

    public function test_an_expired_offer_link_shows_a_message_not_a_404(): void
    {
        $s = $this->offerScenario();
        $window = (int) Setting::get('dispatch.offer_timeout_seconds', 25);
        $this->offer($s['booking'], $s['provider'], now()->subSeconds($window + 60));

        $this->actingAs($s['provider']->user)
            ->get($this->linkFor($s['booking'], $s['provider']))
            ->assertOk()
            ->assertSee('That offer has expired or is no longer available.');
    }

    public function test_a_declined_or_timed_out_offer_link_shows_the_same_message(): void
    {
        $s = $this->offerScenario();
        $this->offer($s['booking'], $s['provider'], now(), 'rejected');

        $this->actingAs($s['provider']->user)
            ->get($this->linkFor($s['booking'], $s['provider']))
            ->assertOk()
            ->assertSee('That offer has expired or is no longer available.');
    }

    public function test_a_booking_accepted_by_this_provider_forwards_to_the_job_page(): void
    {
        $s = $this->offerScenario();
        $s['booking']->update(['provider_id' => $s['provider']->id, 'status' => 'assigned']);

        $this->actingAs($s['provider']->user)
            ->get($this->linkFor($s['booking'], $s['provider']))
            ->assertRedirect(route('provider.jobs.show', $s['booking']->id));
    }

    public function test_an_offer_taken_by_another_provider_is_reported_without_revealing_anything(): void
    {
        $s = $this->offerScenario();
        $other = $this->makeProviderIn($s['franchise'], $s['zone']);
        $this->offer($s['booking'], $s['provider']);
        $s['booking']->update(['provider_id' => $other->id, 'status' => 'assigned']);

        $this->actingAs($s['provider']->user)
            ->get($this->linkFor($s['booking'], $s['provider']))
            ->assertOk()
            ->assertSee('That offer has expired or is no longer available.')
            ->assertDontSee($s['booking']->code)
            ->assertDontSee($other->user->name);
    }

    public function test_a_provider_cannot_use_another_providers_offer_link(): void
    {
        $s = $this->offerScenario();
        $intruder = $this->makeProviderIn($s['franchise'], $s['zone']);
        $this->offer($s['booking'], $s['provider']); // live — but for the OTHER provider

        $this->actingAs($intruder->user)
            ->get($this->linkFor($s['booking'], $s['provider']))
            ->assertOk()
            ->assertSee('That offer has expired or is no longer available.')
            ->assertDontSee($s['booking']->code)
            ->assertDontSee($s['service']->name);

        // The offer itself is untouched by the intruder's visit.
        $this->assertSame('notified', DispatchAttempt::where('booking_id', $s['booking']->id)->value('status'));
    }

    public function test_a_provider_who_holds_a_booking_it_was_never_offered_is_not_confused_with_an_intruder(): void
    {
        // Ownership comes from bookings.provider_id, never from the URL.
        $s = $this->offerScenario();
        $intruder = $this->makeProviderIn($s['franchise'], $s['zone']);
        $s['booking']->update(['provider_id' => $s['provider']->id, 'status' => 'assigned']);

        $this->actingAs($intruder->user)
            ->get(route('provider.jobs.index', ['offer' => $s['booking']->id]))
            ->assertOk()
            ->assertSee('That offer has expired or is no longer available.');
    }

    public function test_a_nonexistent_or_garbage_offer_id_is_a_message_not_an_error(): void
    {
        $s = $this->offerScenario();
        $this->actingAs($s['provider']->user);

        $this->get(route('provider.jobs.index', ['offer' => 99999999]))
            ->assertOk()->assertSee('That offer has expired or is no longer available.');

        $this->get(route('provider.jobs.index', ['offer' => 'abc']))
            ->assertOk()->assertDontSee('no longer available');

        // An array value must not be coerced into booking id 1.
        $this->get(route('provider.jobs.index', ['offer' => ['x' => 1]]))
            ->assertOk()->assertDontSee('no longer available');
    }

    public function test_a_guest_following_the_link_is_sent_to_sign_in(): void
    {
        $s = $this->offerScenario();

        $this->get($this->linkFor($s['booking'], $s['provider']))
            ->assertRedirect(route('provider.login'));
    }

    public function test_opening_the_link_does_not_accept_or_change_the_offer(): void
    {
        $s = $this->offerScenario();
        $attempt = $this->offer($s['booking'], $s['provider']);

        $this->actingAs($s['provider']->user)->get($this->linkFor($s['booking'], $s['provider']))->assertOk();

        $this->assertSame('notified', $attempt->fresh()->status);
        $this->assertNull($s['booking']->fresh()->provider_id);
    }

    public function test_accepting_from_the_link_page_still_uses_the_existing_accept_path(): void
    {
        $s = $this->offerScenario();
        $this->offer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::withQueryParams(['offer' => $s['booking']->id])
            ->test(Index::class)
            ->assertSet('error', '')
            ->call('accept', $s['booking']->id)
            ->assertRedirect(route('provider.jobs.show', $s['booking']->id));

        $this->assertSame($s['provider']->id, $s['booking']->fresh()->provider_id);
    }

    public function test_a_second_provider_cannot_accept_a_job_the_first_already_took(): void
    {
        $s = $this->offerScenario();
        $second = $this->makeProviderIn($s['franchise'], $s['zone']);
        $second->update(['current_lat' => 1.001, 'current_lng' => 1.001, 'location_updated_at' => now(), 'skills' => [$s['service']->category_id], 'is_online' => true]);
        $this->offer($s['booking'], $s['provider']);
        $this->offer($s['booking'], $second);

        $this->actingAs($s['provider']->user);
        Livewire::test(Index::class)->call('accept', $s['booking']->id);

        $this->actingAs($second->user);
        Livewire::test(Index::class)->call('accept', $s['booking']->id)->assertSet('error', fn ($e) => $e !== '');

        $this->assertSame($s['provider']->id, $s['booking']->fresh()->provider_id, 'The first accept must win and stay won.');
    }

    /* ------------------------- alerts on every provider page ------------------------- */

    public function test_the_shared_layout_carries_the_alert_listener_and_the_watcher_on_non_offer_pages(): void
    {
        $s = $this->offerScenario();
        $this->actingAs($s['provider']->user);

        foreach (['provider.earnings', 'provider.history', 'provider.activity', 'provider.payment-accounts', 'provider.request-payout'] as $name) {
            $html = $this->get(route($name))->assertOk()->getContent();

            $this->assertStringContainsString('x-data="providerOfferAlert"', $html, "{$name}: alert banner missing");
            $this->assertSame(1, substr_count($html, 'wire:name="provider.offer-watcher"'), "{$name}: exactly one offer watcher expected");
        }

        $this->get(route('provider.jobs.show', $this->heldJob($s)))->assertOk();
    }

    public function test_the_watcher_is_absent_where_the_page_already_dispatches_offers(): void
    {
        $s = $this->offerScenario();
        $this->actingAs($s['provider']->user);

        foreach (['provider.dashboard', 'provider.jobs.index'] as $name) {
            $html = $this->get(route($name))->assertOk()->getContent();

            $this->assertStringContainsString('x-data="providerOfferAlert"', $html);
            $this->assertStringNotContainsString('wire:name="provider.offer-watcher"', $html, "{$name}: a second offer source would double the polling");
        }
    }

    public function test_the_job_page_also_gets_a_watcher(): void
    {
        $s = $this->offerScenario();
        $held = $this->heldJob($s);

        $html = $this->actingAs($s['provider']->user)->get(route('provider.jobs.show', $held))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'wire:name="provider.offer-watcher"'));
    }

    private function heldJob(array $s): Booking
    {
        $s['booking']->update(['provider_id' => $s['provider']->id, 'status' => 'assigned']);

        return $s['booking']->fresh();
    }

    public function test_the_watcher_dispatches_the_live_offer_set_for_an_online_provider(): void
    {
        $s = $this->offerScenario();
        $this->offer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::test(OfferWatcher::class)
            ->assertDispatched('provider-alert-offers', fn ($event, $params) => $params['count'] === 1
                && $params['offers'][0]['id'] === $s['booking']->id
                && $params['offers'][0]['expires_in'] > 0)
            ->assertSeeHtml('wire:poll.6s');
    }

    public function test_the_watcher_reports_nothing_and_does_not_poll_while_offline(): void
    {
        $s = $this->offerScenario();
        $this->offer($s['booking'], $s['provider']);
        $s['provider']->update(['is_online' => false]);
        $this->actingAs($s['provider']->user);

        Livewire::test(OfferWatcher::class)
            ->assertDispatched('provider-alert-offers', fn ($event, $params) => $params['count'] === 0 && $params['offers'] === [])
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_the_watcher_ignores_expired_taken_and_other_providers_offers(): void
    {
        $s = $this->offerScenario();
        $other = $this->makeProviderIn($s['franchise'], $s['zone']);
        $window = (int) Setting::get('dispatch.offer_timeout_seconds', 25);

        $expired = $this->makeBookingScenario('searching_provider');
        $this->offer($expired['booking'], $s['provider'], now()->subSeconds($window + 30));
        $this->offer($s['booking'], $other); // someone else's live offer
        $this->actingAs($s['provider']->user);

        Livewire::test(OfferWatcher::class)
            ->assertDispatched('provider-alert-offers', fn ($event, $params) => $params['count'] === 0);
    }

    public function test_the_watcher_follows_the_availability_event(): void
    {
        $s = $this->offerScenario();
        $this->actingAs($s['provider']->user);

        $component = Livewire::test(OfferWatcher::class)->assertSeeHtml('wire:poll.6s');

        $s['provider']->update(['is_online' => false]);
        $component->dispatch('provider-availability-changed')->assertDontSeeHtml('wire:poll');
    }

    public function test_the_watcher_is_read_only(): void
    {
        $s = $this->offerScenario();
        $attempt = $this->offer($s['booking'], $s['provider']);
        $this->actingAs($s['provider']->user);

        Livewire::test(OfferWatcher::class);

        $this->assertSame('notified', $attempt->fresh()->status);
        $this->assertNull($s['booking']->fresh()->provider_id);
        $this->assertSame(1, DispatchAttempt::count());
    }
}

<?php

namespace App\Livewire\Provider;

use App\Livewire\Provider\Concerns\InteractsWithProvider;
use App\Models\Booking;
use App\Models\Setting;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Feeds the layout's job-offer banner + ring on every provider page.
 *
 * The banner (`providerOfferAlert`, resources/js/provider-alerts.js) has
 * always lived in the shared layout, but it only reacts to the
 * `provider-alert-offers` browser event, and only Jobs\Index and Dashboard
 * dispatched it — on Earnings, History, Activity, Payment Accounts, Request
 * Payout or a Job page an offer made no sound and showed nothing. This
 * component is the missing source for those pages.
 *
 *   - Display only. It reads the same live-offer predicate the offers page
 *     uses and re-emits the same event; accept/decline/expiry and the
 *     dispatch engine are untouched, and the server list stays the only
 *     authority on whether an offer is live.
 *   - Mounted by the layout everywhere EXCEPT Jobs\Index and Dashboard,
 *     which already dispatch the event from their own poll — so a page never
 *     has two sources.
 *   - Polls only while the provider is online: offers are only ever sent to
 *     online providers (DispatchService), so an offline provider's poll would
 *     be pure waste. Going online/offline in the header re-renders it through
 *     AVAILABILITY_EVENT, which adds/removes the poll and sends the (empty)
 *     set that silences a ring.
 *   - No `.keep-alive`: Livewire throttles a hidden tab's poll, and the
 *     hidden-tab case is FCM's job (firebase-messaging-sw.js), not this.
 */
class OfferWatcher extends Component
{
    use InteractsWithProvider;

    /** False on the pages that already feed the offer banner from their own poll (Jobs\Index, Dashboard). */
    public bool $withOffers = true;

    /** @var list<int> assigned job ids already seen; seeded by the first render. */
    #[Locked]
    public array $seenAssigned = [];

    #[Locked]
    public bool $seeded = false;

    #[On(self::AVAILABILITY_EVENT)]
    public function syncAvailability(): void
    {
    }

    public function render()
    {
        // Not $this->provider(): that eager-loads user + zone for the pages
        // that render them, which this poll never reads. It runs every few
        // seconds for every online provider, so the two extra queries are the
        // ones worth dropping. EnsureIsProvider has already guaranteed the row.
        $provider = auth()->user()->providerProfile()->firstOrFail();
        $online = (bool) $provider->is_online;
        $window = (int) Setting::get('dispatch.offer_timeout_seconds', 25);

        if ($this->withOffers) {
            $offers = $online ? $this->liveOfferAttempts($provider, $window) : collect();

            $this->dispatch('provider-alert-offers', count: $offers->count(), offers: $this->offerAlertSummaries($offers, $window));
        }

        $this->alertOnNewAssignments($provider->id);

        return view('livewire.provider.offer-watcher', ['online' => $online]);
    }

    /**
     * REF 1CF-JOURNEY-001 — a job handed to this provider by an operator (a fresh assignment or a mid-work
     * hand-over) is not an offer, so it never rang. Chime once when a job newly appears as assigned to them
     * that they did not accept themselves. The first render only seeds the set, so opening a page never rings.
     */
    private function alertOnNewAssignments(int $providerId): void
    {
        $jobs = Booking::where('provider_id', $providerId)->where('status', 'assigned')
            ->with(['statusHistory' => fn ($q) => $q->latest('id')->limit(1)])
            ->get(['id', 'code']);
        $ids = $jobs->pluck('id')->all();

        if (! $this->seeded) {
            $this->seeded = true;
            $this->seenAssigned = $ids;

            return;
        }

        $new = $jobs->filter(fn ($b) => ! in_array($b->id, $this->seenAssigned, true)
            && ! str_starts_with((string) $b->statusHistory->first()?->note, 'Accepted by provider'));
        $this->seenAssigned = $ids;

        if ($new->isNotEmpty()) {
            $this->dispatch('provider-alert-status', title: 'Job assigned to you', body: 'Job '.$new->first()->code.' has been assigned to you. Open it to start.');
        }
    }
}

<?php

namespace App\Livewire\Provider;

use App\Actions\SetProviderOnlineStatusAction;
use App\Livewire\Provider\Concerns\BuildsProviderEligibility;
use App\Livewire\Provider\Concerns\DetectsStuckJob;
use App\Livewire\Provider\Concerns\InteractsWithProvider;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Setting;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * PHASE PW1 §3 — the partner home: the online/offline switch (the only
 * write on this screen, through SetProviderOnlineStatusAction), the
 * eligibility panel that names every reason dispatch will or won't reach
 * this provider, and a "needs attention" link to any accepted job that has
 * gone stale (§9). Everything except the toggle is a read.
 */
class Dashboard extends Component
{
    use BuildsProviderEligibility;
    use DetectsStuckJob;
    use InteractsWithProvider;

    public string $notice = '';

    public string $error = '';

    /**
     * Called from the blade after navigator.geolocation resolves (or fails
     * → null, null). The Action is resolved via the container rather than
     * method-injected: this signature mixes an injected dependency with
     * caller-supplied optional args, and app() keeps that unambiguous.
     */
    public function goOnline(?float $lat = null, ?float $lng = null): void
    {
        $this->reset('notice', 'error');

        // The location heartbeat re-calls this while already online; only a
        // real offline → online flip needs to tell the sibling components.
        $wasOnline = (bool) $this->provider()->is_online;

        app(SetProviderOnlineStatusAction::class)->execute($this->provider(), true, $lat, $lng);

        $this->notice = ($lat !== null && $lng !== null)
            ? "You're online."
            : "You're online, but we couldn't read your location — you won't receive jobs until we can. Allow location access and try again.";

        if (! $wasOnline) {
            $this->announceAvailabilityChange();
        }
    }

    public function goOffline(): void
    {
        $this->reset('notice', 'error');
        app(SetProviderOnlineStatusAction::class)->execute($this->provider(), false);
        $this->notice = "You're offline.";

        $this->announceAvailabilityChange();
    }

    /**
     * The header chip (or its drawer copy) changed the status. Receiving the
     * event re-renders this card from the row, so its online/offline state and
     * heartbeat marker follow.
     */
    #[On(self::AVAILABILITY_EVENT)]
    public function syncAvailability(): void
    {
    }

    public function render()
    {
        $provider = $this->provider();

        $activeJob = Booking::where('provider_id', $provider->id)
            ->whereIn('status', ['assigned', 'provider_en_route', 'in_progress'])
            ->with(['service:id,name', 'address:id,label'])
            ->latest('id')
            ->first();

        $checks = $this->eligibilityChecks($provider);

        // Phase PN1 — the dashboard is the screen a working provider leaves
        // open. Count their live offers here too (same window as
        // Jobs\Index) so the chime + tab-hidden OS notification fire even
        // when they're not on the Job Offers page, plus an inline banner.
        $window = (int) Setting::get('dispatch.offer_timeout_seconds', 25);
        $liveOffers = DispatchAttempt::query()
            ->where('provider_id', $provider->id)
            ->where('status', 'notified')
            ->where('notified_at', '>=', now()->subSeconds($window))
            ->whereHas('booking', fn ($q) => $q->whereIn('status', ['pending', 'searching_provider']))
            ->with(['booking.service:id,name', 'booking.address:id,label', 'booking.franchise.country'])
            ->latest('notified_at')
            ->get();
        $pendingOffers = $liveOffers->count();

        $this->dispatch('provider-alert-offers', count: $pendingOffers, offers: $this->offerAlertSummaries($liveOffers, $window));

        return view('livewire.provider.dashboard', [
            'provider' => $provider,
            'checks' => $checks,
            'dispatchBlocked' => $this->dispatchBlocked($checks),
            'activeJob' => $activeJob,
            'stuckMinutes' => $activeJob ? $this->stuckMinutes($activeJob) : null,
            'pendingOffers' => $pendingOffers,
        ])->layout('components.layouts.provider', ['title' => 'Partner dashboard']);
    }
}

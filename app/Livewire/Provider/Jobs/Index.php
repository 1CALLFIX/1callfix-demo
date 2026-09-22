<?php

namespace App\Livewire\Provider\Jobs;

use App\Actions\AcceptBookingAction;
use App\Livewire\Provider\Concerns\InteractsWithProvider;
use App\Models\Booking;
use App\Models\DispatchAttempt;
use App\Models\Setting;
use Livewire\Component;

/**
 * PHASE PW1 §4 — incoming job offers (polled) plus a persistent link to the
 * one job this partner currently holds, so an accepted job is never off
 * screen (feeds §9).
 *
 *   - Accept  → App\Actions\AcceptBookingAction, verbatim the call
 *               API\DispatchController::accept() makes.
 *   - Decline → §4.3: a single guarded status write on the partner's own
 *               dispatch_attempts row (notified → rejected). No new Action:
 *               'rejected' is already defined in the schema and already
 *               honoured as a permanent per-booking exclusion by
 *               DispatchService::excludedProviderIdsForBooking(); the
 *               booking itself does not change state.
 */
class Index extends Component
{
    use InteractsWithProvider;

    public string $error = '';

    public string $notice = '';

    /**
     * Landing point of a job-offer push (ProviderJobOfferNotification::pushLink,
     * `?offer={bookingId}`). The id is only a hint — every lookup below is
     * scoped to the signed-in provider, so it can neither reveal nor act on
     * anyone else's offer:
     *
     *   - already accepted by THIS provider → on to the job page;
     *   - no live offer for THIS provider (expired, declined, taken by
     *     another provider, cancelled, or simply not theirs) → one neutral
     *     message. The wording is identical for every reason, on purpose.
     *
     * A live offer needs no special handling: it is in the list below.
     */
    public function mount(): void
    {
        // Digits only: (int) of an array or junk would otherwise quietly turn
        // into a real booking id (?offer[]=x → 1).
        $raw = request()->query('offer');
        $bookingId = is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;

        if ($bookingId <= 0) {
            return;
        }

        $provider = $this->provider();

        if (Booking::where('id', $bookingId)->where('provider_id', $provider->id)->exists()) {
            $this->redirectRoute('provider.jobs.show', ['booking' => $bookingId], navigate: true);

            return;
        }

        $window = (int) Setting::get('dispatch.offer_timeout_seconds', 25);

        $isLive = DispatchAttempt::where('booking_id', $bookingId)
            ->where('provider_id', $provider->id)
            ->where('status', 'notified')
            ->where('notified_at', '>=', now()->subSeconds($window))
            ->whereHas('booking', fn ($q) => $q->whereIn('status', ['pending', 'searching_provider']))
            ->exists();

        if (! $isLive) {
            $this->error = 'That offer has expired or is no longer available.';
        }
    }

    public function accept(int $bookingId, AcceptBookingAction $action): void
    {
        $this->reset('error', 'notice');
        $provider = $this->provider();

        $hasLiveOffer = DispatchAttempt::where('booking_id', $bookingId)
            ->where('provider_id', $provider->id)
            ->where('status', 'notified')
            ->exists();

        if (! $hasLiveOffer) {
            $this->error = 'That offer is no longer available.';

            return;
        }

        try {
            $action->execute($bookingId, $provider);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->redirectRoute('provider.jobs.show', ['booking' => $bookingId], navigate: true);
    }

    public function decline(int $bookingId): void
    {
        $this->reset('error', 'notice');
        $provider = $this->provider();

        $attempt = DispatchAttempt::where('booking_id', $bookingId)
            ->where('provider_id', $provider->id)
            ->where('status', 'notified')
            ->first();

        abort_unless((bool) $attempt, 404);

        $attempt->update(['status' => 'rejected', 'responded_at' => now()]);
        $this->notice = 'Offer declined.';
    }

    public function render()
    {
        $provider = $this->provider();
        $window = (int) Setting::get('dispatch.offer_timeout_seconds', 25);

        $offers = DispatchAttempt::query()
            ->where('provider_id', $provider->id)
            ->where('status', 'notified')
            ->where('notified_at', '>=', now()->subSeconds($window))
            ->with([
                'booking.service:id,name',
                'booking.address:id,label,address_line',
                'booking.customer:id,name',
                // TimezoneResolver reads franchise->country->default_timezone;
                // eager-loaded here so rendering N offers stays N+1-free, the
                // same caller-supplies-its-own-relations convention that class
                // documents.
                'booking.franchise.country',
            ])
            ->latest('notified_at')
            ->get()
            ->filter(fn (DispatchAttempt $a) => $a->booking
                && in_array($a->booking->status, ['pending', 'searching_provider'], true))
            ->values();

        $activeJob = Booking::where('provider_id', $provider->id)
            ->whereIn('status', ['assigned', 'provider_en_route', 'in_progress'])
            ->with(['service:id,name', 'address:id,label', 'franchise.country'])
            ->latest('id')
            ->first();

        // Phase PN1 — drives the foreground alarm + (tab-hidden) OS
        // notification in resources/js/provider-alerts.js. Fired on every
        // poll: the JS starts the repeating ring on the first count > 0 and
        // stops it when the offer set clears, so the cadence of the ring is
        // the JS loop's, not this 4s poll's. `offers` is display-only
        // detail for the layout banner; `count` stays the contract.
        $this->dispatch('provider-alert-offers', count: $offers->count(), offers: $this->offerAlertSummaries($offers, $window));

        return view('livewire.provider.jobs.index', [
            'offers' => $offers,
            'offerWindowSeconds' => $window,
            'activeJob' => $activeJob,
        ])->layout('components.layouts.provider', ['title' => 'Jobs']);
    }
}

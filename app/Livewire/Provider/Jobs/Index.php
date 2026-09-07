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

        // Phase PN1 — drives the foreground chime + (tab-hidden) OS
        // notification in public/js/provider-alerts.js. Fired on every
        // poll: the JS starts the repeating alarm on the first count > 0 and
        // stops it when the offer set clears, so the cadence of the alarm is
        // the JS loop's, not this 4s poll's.
        $this->dispatch('provider-alert-offers', count: $offers->count());

        return view('livewire.provider.jobs.index', [
            'offers' => $offers,
            'offerWindowSeconds' => $window,
            'activeJob' => $activeJob,
        ])->layout('components.layouts.provider', ['title' => 'Jobs']);
    }
}

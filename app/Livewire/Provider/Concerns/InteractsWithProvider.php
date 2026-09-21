<?php

namespace App\Livewire\Provider\Concerns;

use App\Models\DispatchAttempt;
use App\Models\Provider;
use App\Services\TimezoneResolver;
use Illuminate\Support\Collection;

/**
 * PHASE PW1 — every provider-web component resolves "the current partner"
 * the same way. EnsureIsProvider has already guaranteed the row exists, so
 * firstOrFail() here is a belt-and-braces assertion, never the real guard.
 */
trait InteractsWithProvider
{
    protected function provider(): Provider
    {
        return auth()->user()->providerProfile()->with(['user:id,name,phone', 'zone:id,name'])->firstOrFail();
    }

    /**
     * The header chip, its drawer twin and the Dashboard card are separate
     * Livewire components over ONE providers.is_online row. When one of them
     * changes it, the others must re-render, or they keep showing (and, for
     * the online state, keep running the location heartbeat of) a status the
     * provider has already left. Listeners are `#[On(AVAILABILITY_EVENT)]` on
     * OnlineToggle and Dashboard; this decides nothing, it only tells the
     * siblings to re-read the row.
     */
    protected const AVAILABILITY_EVENT = 'provider-availability-changed';

    protected function announceAvailabilityChange(): void
    {
        $this->dispatch(self::AVAILABILITY_EVENT);
    }

    /**
     * The provider's live offers — `notified`, inside the offer window, on a
     * booking that is still unassigned. Same predicate Jobs\Index and
     * Dashboard apply, with everything offerAlertSummaries() needs
     * eager-loaded. Read-only: nothing here accepts, declines or expires an
     * offer; that stays with AcceptBookingAction and the dispatch jobs.
     *
     * @return Collection<int, DispatchAttempt>
     */
    protected function liveOfferAttempts(Provider $provider, int $windowSeconds): Collection
    {
        return DispatchAttempt::query()
            ->where('provider_id', $provider->id)
            ->where('status', 'notified')
            ->where('notified_at', '>=', now()->subSeconds($windowSeconds))
            ->whereHas('booking', fn ($q) => $q->whereIn('status', ['pending', 'searching_provider']))
            ->with(['booking.service:id,name', 'booking.address:id,label', 'booking.franchise.country'])
            ->latest('notified_at')
            ->get();
    }

    /**
     * Display-only summary of the live offers, shipped on the
     * `provider-alert-offers` browser event next to the (unchanged) `count`
     * so the layout's offer banner can name the job and run a countdown.
     *
     * This decides nothing: callers pass the offers they already resolved
     * with the authoritative offer-window query, and the client replaces
     * its whole set from every event — an offer that is not in the next
     * event is gone. `expires_in` is the same seconds-left figure the
     * offers list renders, so the banner and the list cannot disagree.
     *
     * Callers must have eager-loaded booking.service, booking.address and
     * booking.franchise.country.
     *
     * @param  Collection<int, DispatchAttempt>  $offers
     * @return list<array<string, mixed>>
     */
    protected function offerAlertSummaries(Collection $offers, int $windowSeconds): array
    {
        $tz = app(TimezoneResolver::class);

        return $offers->map(function (DispatchAttempt $a) use ($windowSeconds, $tz) {
            $b = $a->booking;

            return [
                'id' => $a->booking_id,
                'service' => $b->service?->name ?? 'Service',
                'code' => $b->code,
                'price' => '₹'.number_format((float) $b->price_quoted, 2),
                'distance' => $a->distance_km !== null ? number_format((float) $a->distance_km, 1).' km' : null,
                'when' => $b->scheduled_at ? $tz->format($b->scheduled_at, $b->franchise, 'j M, g:i A') : 'ASAP',
                'area' => $b->address?->label,
                'expires_in' => max(0, (int) now()->diffInSeconds($a->notified_at->copy()->addSeconds($windowSeconds), false)),
            ];
        })->values()->all();
    }
}

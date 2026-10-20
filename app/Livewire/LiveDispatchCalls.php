<?php

namespace App\Livewire;

use App\Models\Booking;
use App\Services\AuthorizationService;
use App\Services\TimezoneResolver;
use App\Support\DispatchTimeline;
use Livewire\Component;

/**
 * Owner request 2026-10-20 — a bar on every admin page listing instant bookings still searching for a
 * provider, each with its stage (calling providers / waiting for an admin) and a live countdown to the
 * auto-cancel. In the last dispatch.admin_warning_minutes the row turns red and, when the admin has switched
 * sound on, the browser beeps. Mounted once in layouts.admin; scoped like the bookings list (bookings.view).
 */
class LiveDispatchCalls extends Component
{
    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    public function calls()
    {
        $user = auth()->user();
        if (! $user || ! $user->hasPermissionAnywhere('bookings.view')) {
            return collect();
        }

        $columns = ['zone_id' => 'zone_id', 'franchise_id' => 'franchise_id', 'city_id' => 'franchise.city_id', 'country_id' => 'franchise.country_id'];

        return app(AuthorizationService::class)
            ->scopeQuery(Booking::query(), $user, 'bookings.view', $columns)
            ->with('service:id,name')
            ->where('status', 'searching_provider')
            ->whereNull('scheduled_at')
            ->whereNotNull('dispatch_deadline_at')
            ->orderBy('dispatch_deadline_at')
            ->limit(20)
            ->get()
            ->map(function (Booking $booking) {
                $t = DispatchTimeline::forBooking($booking);

                return $t === null ? null : [
                    'id' => $booking->id,
                    'code' => $booking->code,
                    'service' => $booking->service?->name,
                    'broadcast_ends_at' => $t['broadcast_ends_at']->getTimestampMs(),
                    'cancels_at' => $t['cancels_at']->getTimestampMs(),
                    'warn_at' => $t['warn_at']->getTimestampMs(),
                    'cancels_at_text' => $t['cancels_at']->copy()->timezone(app(TimezoneResolver::class)->platformTimezone())->format('g:i A'),
                ];
            })
            ->filter()
            ->values();
    }

    public function render()
    {
        return view('livewire.live-dispatch-calls', ['calls' => $this->calls()]);
    }
}

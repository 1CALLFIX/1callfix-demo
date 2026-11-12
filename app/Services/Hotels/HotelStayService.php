<?php

namespace App\Services\Hotels;

use App\Actions\CheckInHotelReservationAction;
use App\Actions\CheckOutHotelReservationAction;
use App\Actions\CompleteHotelReservationAction;
use App\Actions\ConfirmHotelReservationAction;
use App\Events\HotelReservationStatusUpdated;
use App\Models\HotelReservation;
use App\Notifications\HotelNewBookingNotification;
use App\Notifications\HotelReservationStatusNotification;
use App\Notifications\Support\ChannelResolver;
use App\Services\CancellationService;
use App\Services\CommissionService;
use App\Services\HotelAvailabilityService;
use App\Support\HotelPricing;
use App\Support\HotelSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hotels H3 — the stay, every rule a Hotel Settings value (owner 2026-11-12):
 *  - paid → confirmed at once with a 4-digit check-in code, or (hotel must accept) waits until accept_by; the hotel
 *    accepts or declines; not accepted in time → cancelled and refunded in full;
 *  - check-in only with the customer's code, from check-in day; check-out; the stay is completed (commission, hotel
 *    paid through its wallet) payout_hold_hours after check-out, so a guest can still report a problem;
 *  - customer cancels: free until free_cancel_hours before check-in, later late_cancel_nights are charged; the hotel
 *    marks a no-show from check-in day: no_show_nights charged; the rest goes back to how it was paid. The hotel
 *    earns its share of the room part of any charge (same commission rule).
 */
class HotelStayService
{
    public function __construct(
        private CancellationService $cancellations,
        private HotelAvailabilityService $availability,
    ) {
    }

    // ───────────────────────── paid → confirmed / waiting for the hotel ─────────────────────────

    public function afterPaid(HotelReservation $r): void
    {
        $r->loadMissing('accommodation.provider.user', 'franchise');
        $mustAccept = $r->accommodation->needsAcceptance();
        if ($r->status === 'pending' && $mustAccept) {
            $minutes = (int) HotelSettings::get('hotel.accept_window_minutes', HotelPricing::scope($r->accommodation));
            $r->forceFill(['accept_by' => now()->addMinutes($minutes)])->save();
        } elseif ($r->status === 'pending') {
            $this->confirm($r);
        }
        $this->tellHotel($r->fresh(), $mustAccept);
    }

    public function accept(HotelReservation $r): HotelReservation
    {
        if ($r->status !== 'pending' || $r->payment_status !== 'paid') {
            throw new \RuntimeException('This booking cannot be accepted now.');
        }

        return $this->confirm($r);
    }

    private function confirm(HotelReservation $r): HotelReservation
    {
        $r->forceFill(['check_in_code' => $this->newCode($r), 'accept_by' => null])->save();

        return app(ConfirmHotelReservationAction::class)->execute($r->id);
    }

    /** 4 digits, different from the hotel's other open bookings (the desk types it in). */
    private function newCode(HotelReservation $r): string
    {
        $taken = HotelReservation::where('accommodation_id', $r->accommodation_id)->whereIn('status', ['pending', 'confirmed', 'checked_in'])
            ->whereNotNull('check_in_code')->pluck('check_in_code')->all();
        do {
            $code = (string) random_int(1000, 9999);
        } while (in_array($code, $taken, true));

        return $code;
    }

    private function tellHotel(HotelReservation $r, bool $mustAccept): void
    {
        $owner = $r->accommodation?->provider?->user;
        if ($owner) {
            // A message that cannot be sent (mail server down) is logged and never stops the booking (2026-11-12).
            rescue(fn () => $owner->notify(new HotelNewBookingNotification($r, ChannelResolver::resolve(array_filter(['zone_id' => $r->zone_id, 'franchise_id' => $r->franchise_id])), $mustAccept)));
        }
    }

    public function decline(HotelReservation $r, string $reason): HotelReservation
    {
        if ($r->status !== 'pending') {
            throw new \RuntimeException('Only a booking waiting for you can be declined.');
        }

        return $this->cancel($r, 'hotel', 'Declined by the hotel: '.$reason, 0, 0);
    }

    /** hotels:expire-unaccepted — paid bookings the hotel did not accept in time: cancelled, refunded in full. */
    public function expireUnaccepted(): int
    {
        $n = 0;
        HotelReservation::where('status', 'pending')->where('payment_status', 'paid')->whereNotNull('accept_by')->where('accept_by', '<', now())
            ->orderBy('id')->limit(200)->get()
            ->each(function (HotelReservation $r) use (&$n) {
                $this->cancel($r, 'system', 'The hotel did not accept in time', 0, 0);
                $n++;
            });

        return $n;
    }

    // ───────────────────────── the stay ─────────────────────────

    public function checkIn(HotelReservation $r, string $code): HotelReservation
    {
        if ($r->status !== 'confirmed') {
            throw new \RuntimeException('Only a confirmed booking can be checked in.');
        }
        if ($this->localToday($r)->lt($r->check_in_date->copy()->startOfDay())) {
            throw new \RuntimeException('Check-in opens on '.$r->check_in_date->format('j M').'.');
        }
        if ($r->check_in_code === null || ! hash_equals((string) $r->check_in_code, trim($code))) {
            throw new \RuntimeException('Wrong code. Ask the guest for the 4-digit code on their booking.');
        }

        return app(CheckInHotelReservationAction::class)->execute($r->id);
    }

    public function checkOut(HotelReservation $r): HotelReservation
    {
        return app(CheckOutHotelReservationAction::class)->execute($r->id);
    }

    /** hotels:complete-stays — checked out longer than the payout hold: completed, commission split, hotel paid. */
    public function completeDue(): int
    {
        $n = 0;
        HotelReservation::where('status', 'checked_out')->whereNotNull('checked_out_at')->with('accommodation')->orderBy('id')->limit(200)->get()
            ->each(function (HotelReservation $r) use (&$n) {
                $hours = (int) HotelSettings::get('hotel.payout_hold_hours', HotelPricing::scope($r->accommodation));
                if ($r->checked_out_at->copy()->addHours($hours)->isFuture()) {
                    return;
                }
                app(CompleteHotelReservationAction::class)->execute($r->id);
                $n++;
            });

        return $n;
    }

    // ───────────────────────── cancellations and no-shows ─────────────────────────

    /**
     * What a cancellation now would keep: [total kept, room part of it]. Free until Hotel Settings' free time, then
     * late_cancel_nights (or no_show_nights) of room + GST, plus the convenience fee.
     *
     * @return array{0: float, 1: float}
     */
    public function chargeFor(HotelReservation $r, bool $noShow = false): array
    {
        $r->loadMissing('accommodation.franchise');
        $scope = HotelPricing::scope($r->accommodation);
        if (! $noShow) {
            $until = HotelPricing::freeCancelUntil($r->accommodation, $r->check_in_date->toDateString());
            if ($until === null || $until->isFuture()) {
                return [0.0, 0.0];
            }
        }
        $nights = (int) HotelSettings::get($noShow ? 'hotel.no_show_nights' : 'hotel.late_cancel_nights', $scope);
        $charged = min(max(0, $nights), max(1, (int) $r->number_of_nights));
        if ($charged === 0) {
            return [0.0, 0.0];
        }
        $share = $charged / max(1, (int) $r->number_of_nights);
        $room = round((float) $r->price_quoted * $share, 2);
        $total = min($r->amountDue(), round($room + (float) $r->tax_amount * $share + (float) $r->convenience_fee, 2));

        return [$total, $room];
    }

    public function customerCancel(HotelReservation $r): HotelReservation
    {
        if (! in_array($r->status, ['pending', 'confirmed'], true) || $r->payment_status !== 'paid') {
            throw new \RuntimeException('This booking cannot be cancelled here. Please call us.');
        }
        if ($this->localToday($r)->gt($r->check_in_date->copy()->startOfDay())) {
            throw new \RuntimeException('Your check-in day has passed. Please call us.');
        }
        [$total, $room] = $this->chargeFor($r);

        return $this->cancel($r, 'customer', 'Cancelled by the customer', $total, $room);
    }

    public function noShow(HotelReservation $r): HotelReservation
    {
        if ($r->status !== 'confirmed') {
            throw new \RuntimeException('Only a confirmed booking can be marked as not arrived.');
        }
        if ($this->localToday($r)->lt($r->check_in_date->copy()->startOfDay())) {
            throw new \RuntimeException('You can mark "did not arrive" from the check-in day.');
        }
        [$total, $room] = $this->chargeFor($r, true);
        $r->forceFill(['no_show' => true])->save();

        return $this->cancel($r, 'hotel', 'Guest did not arrive', $total, $room);
    }

    /** One way to cancel: rooms freed, the charge kept, the rest refunded where it came from, the hotel paid its share. */
    private function cancel(HotelReservation $r, string $by, string $note, float $charge, float $roomPart): HotelReservation
    {
        $r = DB::transaction(function () use ($r, $by, $note, $charge, $roomPart) {
            $locked = HotelReservation::with('rooms.roomType')->lockForUpdate()->findOrFail($r->id);
            if (in_array($locked->status, ['cancelled', 'checked_out', 'completed'], true)) {
                throw new \RuntimeException('This booking is already '.$locked->status.'.');
            }
            $locked->forceFill([
                'status' => 'cancelled', 'cancelled_by' => $by, 'cancellation_note' => $note, 'cancellation_fee' => $charge,
                'price_final' => $roomPart > 0 ? $roomPart : null, 'accept_by' => null,
            ])->save();
            foreach ($locked->rooms as $line) {
                $this->availability->releaseRooms($line->roomType, $locked->check_in_date->toDateString(), $locked->check_out_date->toDateString(), $line->room_count);
            }
            $locked->statusHistory()->create(['status' => 'cancelled', 'note' => $note.($charge > 0 ? " (kept {$charge})" : ''), 'changed_at' => now()]);
            event(new HotelReservationStatusUpdated($locked));

            return $locked->fresh();
        });

        $this->cancellations->refundIfPaidForHotelReservation($r, $charge);
        if ($roomPart > 0) {
            app(CommissionService::class)->applyForHotelReservation($r->fresh());
        }
        if ($r->customer) {
            // A message that cannot be sent (mail server down) is logged and never stops the booking (2026-11-12).
            rescue(fn () => $r->customer->notify(new HotelReservationStatusNotification('cancelled', $r, ChannelResolver::resolve(array_filter(['zone_id' => $r->zone_id, 'franchise_id' => $r->franchise_id])))));
        }

        return $r->fresh();
    }

    private function localToday(HotelReservation $r): Carbon
    {
        return Carbon::parse(app(\App\Services\TimezoneResolver::class)->format(now(), $r->franchise, 'Y-m-d'));
    }
}

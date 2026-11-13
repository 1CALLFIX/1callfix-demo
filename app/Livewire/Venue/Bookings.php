<?php

namespace App\Livewire\Venue;

use App\Livewire\Venue\Concerns\InteractsWithHalls;
use App\Models\HallBooking;
use App\Services\Halls\HallBookingService;
use App\Services\TimezoneResolver;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Hall portal → Bookings (Function halls R2): paid bookings waiting for the owner (Accept / Decline with a reason the
 * customer sees, before the answer time runs out, or it is declined and refunded by itself), then upcoming confirmed
 * bookings and past ones. The owner sees the customer's name, phone, occasion and guests once paid.
 */
class Bookings extends Component
{
    use InteractsWithHalls;

    #[Url(as: 'show')]
    public string $tab = 'upcoming';

    public ?int $decliningId = null;
    public string $reason = '';
    public string $notice = '';
    public string $error = '';

    private function ownBookings()
    {
        return HallBooking::with(['hall', 'slot'])->whereIn('hall_id', $this->halls()->pluck('id'));
    }

    private function own(int $id): HallBooking
    {
        return $this->ownBookings()->find($id) ?? abort(404);
    }

    public function accept(int $id, HallBookingService $halls): void
    {
        $this->reset('notice', 'error');
        $booking = $this->own($id); // 404 for another owner's booking (outside the try: a 404 is a RuntimeException too)
        try {
            $b = $halls->accept($booking, auth()->user());
            $this->notice = "Accepted. {$b->customer_name} is told the hall is booked.";
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function startDecline(int $id): void
    {
        $this->own($id);
        $this->decliningId = $id;
        $this->reset('reason', 'notice', 'error');
    }

    public function decline(HallBookingService $halls): void
    {
        $this->reset('notice', 'error');
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:300']], ['reason.required' => 'Tell the customer why, e.g. Already booked that day.']);
        $booking = $this->own((int) $this->decliningId);
        try {
            $halls->decline($booking, auth()->user(), $this->reason);
            $this->notice = 'Declined. The customer gets all their money back.';
            $this->reset('decliningId', 'reason');
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $tz = app(TimezoneResolver::class)->platformTimezone();
        $waiting = (clone $this->ownBookings())->where('status', 'awaiting_owner')->orderBy('accept_by')->get();
        $list = match ($this->tab) {
            'past' => (clone $this->ownBookings())->whereIn('status', ['confirmed', 'completed', 'declined', 'cancelled'])->where('ends_at', '<', now())->orderByDesc('starts_at')->limit(50)->get(),
            default => (clone $this->ownBookings())->where('status', 'confirmed')->where('ends_at', '>=', now())->orderBy('starts_at')->limit(100)->get(),
        };

        return view('livewire.venue.bookings', [
            'waiting' => $waiting,
            'list' => $list,
            'tz' => $tz,
            'many' => $this->halls()->count() > 1,
        ])->layout('components.layouts.venue', ['title' => 'Bookings']);
    }
}

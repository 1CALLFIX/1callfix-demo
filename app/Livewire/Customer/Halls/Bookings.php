<?php

namespace App\Livewire\Customer\Halls;

use App\Models\HallBooking;
use App\Support\SiteText;
use Livewire\Component;

/** Function halls R2 — /halls/bookings (signed in): the customer's hall bookings, upcoming first. */
class Bookings extends Component
{
    public function render()
    {
        $bookings = HallBooking::with('hall')->where('customer_id', auth()->id())
            ->whereNotIn('status', ['expired'])->latest('id')->limit(50)->get()
            ->sortBy(fn ($b) => [$b->ends_at->isPast() ? 1 : 0, $b->starts_at->timestamp]);

        return view('livewire.customer.halls.bookings', ['bookings' => $bookings])
            ->layout('components.layouts.customer', ['title' => SiteText::get('hall.bookings.title'), 'indexable' => false]);
    }
}

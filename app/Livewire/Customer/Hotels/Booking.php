<?php

namespace App\Livewire\Customer\Hotels;

use App\Models\HotelReservation;
use App\Models\Setting;
use App\Services\Hotels\HotelBookingService;
use App\Support\SiteText;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Hotels H2b — /hotels/bookings/{code}: the customer's own hotel booking. While an online booking is unpaid its rooms
 * are held (Hotel Settings) and Pay stays here; after Razorpay's success handler (?paid=1) it says "confirming your
 * payment" until the webhook marks it paid, never a second Pay. Then: confirmed, or waiting for the hotel to accept
 * (H3), with the stay, guest and price. H3 adds the e-voucher and check-in code.
 */
class Booking extends Component
{
    public string $code = '';

    #[Url(as: 'paid')]
    public bool $justPaid = false;

    public string $error = '';

    public string $notice = '';

    public bool $confirmingCancel = false;

    /** Step 3: "Change dates" — the new dates the guest wants to see prices for. */
    public string $newIn = '';

    public string $newOut = '';

    /** Hotels H4: verified-stay review. */
    public int $rating = 0;

    /** @var array<string, int> */
    public array $scores = [];

    public string $reviewComment = '';

    public function mount(string $code): void
    {
        $this->code = $code;
        $this->reservation();
    }

    private function reservation(): HotelReservation
    {
        return HotelReservation::where('code', $this->code)->where('customer_id', auth()->id())
            ->with(['accommodation.franchise', 'rooms.roomType', 'rooms.ratePlan', 'guests'])->firstOrFail();
    }

    public function pay(HotelBookingService $bookings): void
    {
        $this->reset('error');
        try {
            $checkout = $bookings->startOnlinePayment($this->reservation());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->error = SiteText::get('hotel.booking.pay_failed');

            return;
        }
        $here = route('customer.hotels.booking', $this->code);
        $this->dispatch('razorpay-open', order: $checkout, done: $here.'?paid=1', back: $here)->self();
    }

    /** Hotels H3: cancel by the Hotel Settings rule; the page showed the exact refund before this button. */
    public function cancel(\App\Services\Hotels\HotelStayService $stays): void
    {
        $this->reset('error', 'notice');
        try {
            $stays->customerCancel($this->reservation());
            $this->notice = SiteText::get('hotel.booking.cancelled_notice');
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
        $this->confirmingCancel = false;
    }

    public function submitReview(\App\Services\Hotels\HotelReviewService $reviews): void
    {
        $this->reset('error', 'notice');
        $this->validate(['rating' => ['required', 'integer', 'between:1,5'], 'reviewComment' => ['nullable', 'string', 'max:1000']],
            ['rating.between' => SiteText::get('hotel.review.need_stars')]);
        try {
            $reviews->submit($this->reservation(), auth()->user(), $this->rating, $this->scores, $this->reviewComment);
            $this->notice = SiteText::get('hotel.review.thanks');
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Step 3: new dates → the booking page for the same room, marked as replacing this booking. */
    public function changeDates()
    {
        $this->reset('error');
        $r = $this->reservation();
        $this->validate([
            'newIn' => ['required', 'date', 'after_or_equal:today'],
            'newOut' => ['required', 'date', 'after:newIn'],
        ], [], ['newIn' => 'check-in', 'newOut' => 'check-out']);
        try {
            app(HotelBookingService::class)->assertCanBeReplaced($r, auth()->user(), $r->accommodation);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return null;
        }
        $line = $r->rooms->first();

        return $this->redirectRoute('customer.hotels.book', array_filter([
            'hotel' => $r->accommodation_id, 'room' => $line?->hotel_room_type_id, 'plan' => $line?->hotel_rate_plan_id,
            'in' => $this->newIn, 'out' => $this->newOut, 'rooms' => (int) ($line?->room_count ?? 1),
            'adults' => (int) $r->number_of_adults, 'children' => (int) $r->number_of_children ?: null, 'replace' => $r->code,
        ]), navigate: true);
    }

    public function render()
    {
        $r = $this->reservation();
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $money = fn (float $v) => $symbol.number_format($v, 2);
        $awaiting = $r->isAwaitingOnlinePayment() && $r->payment_expires_at?->isFuture();
        $state = match (true) {
            $r->status === 'cancelled' && $r->payment_status === 'pending' => 'expired',
            $r->status === 'cancelled' => 'cancelled',
            $awaiting && $this->justPaid => 'confirming',
            $awaiting => 'unpaid',
            $r->status === 'pending' && $r->payment_status === 'paid' => 'waiting_hotel',
            default => $r->status,
        };

        return view('livewire.customer.hotels.booking', [
            'r' => $r,
            'state' => $state,
            'money' => $money,
            'minutesLeft' => $awaiting ? max(1, (int) ceil(now()->diffInSeconds($r->payment_expires_at) / 60)) : null,
            'poll' => in_array($state, ['confirming', 'unpaid', 'waiting_hotel'], true),
            'canReview' => app(\App\Services\Hotels\HotelReviewService::class)->canReview($r, auth()->user()),
            'review' => \App\Models\Review::where('hotel_reservation_id', $r->id)->first(),
            'foodUrl' => ($city = $r->accommodation->franchise?->city) && in_array($r->status, ['confirmed', 'checked_in'], true) && \App\Support\Seo\CityModules::foodLiveIn($city)
                ? route('customer.food.home', $city->slug) : null,
            'canCancel' => in_array($r->status, ['pending', 'confirmed'], true) && $r->payment_status === 'paid',
            'canChange' => rescue(fn () => app(HotelBookingService::class)->assertCanBeReplaced($r, auth()->user(), $r->accommodation) === null, false, false),
            'replacedBy' => $r->status === 'cancelled' ? HotelReservation::where('replaces_reservation_id', $r->id)->where('customer_id', auth()->id())->whereIn('status', ['confirmed', 'checked_in', 'checked_out', 'completed'])->value('code') : null,
            'charge' => in_array($r->status, ['pending', 'confirmed'], true) && $r->payment_status === 'paid' ? app(\App\Services\Hotels\HotelStayService::class)->chargeFor($r)[0] : 0.0,
        ])->layout('components.layouts.customer', ['title' => SiteText::get('hotel.booking.title', ['code' => $r->code]), 'indexable' => false]);
    }
}

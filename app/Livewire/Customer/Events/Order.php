<?php

namespace App\Livewire\Customer\Events;

use App\Models\EventOrder;
use App\Models\Setting;
use App\Services\Events\EventTicketService;
use App\Support\SiteText;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Events E2 — /events/orders/{code} (the buyer only): the tickets, each with its QR code for the entrance, or — while
 * the payment is not confirmed yet — Pay now until the hold ends (the page checks again by itself).
 */
class Order extends Component
{
    #[Locked]
    public string $code = '';

    public string $error = '';

    public function mount(string $code): void
    {
        $this->code = $code;
        $this->order();
    }

    private function order(): EventOrder
    {
        return EventOrder::with(['event.franchise.city', 'event.organiser', 'items', 'tickets.ticketType'])
            ->where('code', $this->code)->where('customer_id', auth()->id())->firstOrFail();
    }

    public function payNow(EventTicketService $tickets): void
    {
        $this->reset('error');
        $order = $this->order();
        try {
            $checkout = $tickets->startOnlinePayment($order);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $url = route('customer.events.order', $order->code);
        $this->dispatch('razorpay-open', order: $checkout, done: $url.'?paid=1', back: $url)->self();
    }

    /** Events E3: the buyer cancels (only while Event Settings allows it); the booking fee is kept. */
    public function cancelTickets(EventTicketService $tickets): void
    {
        $this->reset('error');
        try {
            $amount = $tickets->buyerCancel($this->order(), auth()->user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->refunded = $amount;
    }

    public ?float $refunded = null;

    public function render()
    {
        $order = $this->order();
        $event = $order->event;
        $tz = app(\App\Services\TimezoneResolver::class)->timezoneFor($event->franchise);
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return view('livewire.customer.events.order', [
            'order' => $order,
            'event' => $event,
            'when' => $event->starts_at->copy()->setTimezone($tz)->format('l, j F Y · g:i A'),
            'holdUntil' => $order->payment_expires_at?->copy()->setTimezone($tz)->format('g:i A'),
            'money' => fn (float $v) => $symbol.number_format($v, 2),
            'eventUrl' => $event->franchise?->city ? route('customer.events.show', [$event->franchise->city->slug, $event->slug]) : null,
            'justPaid' => request()->boolean('paid'),
            'canCancel' => EventTicketService::buyerCanCancel($order),
        ])->layout('components.layouts.customer', ['title' => SiteText::get('event.order.title'), 'indexable' => false]);
    }
}

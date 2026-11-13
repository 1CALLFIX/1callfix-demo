<?php

namespace App\Livewire\Customer\Events;

use App\Models\Event;
use App\Models\Setting;
use App\Services\Events\EventTicketService;
use App\Services\WalletService;
use App\Support\SiteText;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Events E2 — /events/book?event=…&t[type]=n (signed in): the bill for the chosen tickets (ticket price, GST, booking
 * fee, total: the EventTicketService quote that is charged), the name on the tickets, then Pay online (Razorpay) or
 * from the wallet. Online: the tickets are held while paying; the tickets page shows them once the bank confirms.
 */
class Book extends Component
{
    #[Locked]
    public int $eventId = 0;

    /** @var array<int, int> ticket type id => how many */
    #[Locked]
    public array $quantities = [];

    public string $buyerName = '';
    public string $buyerPhone = '';
    public string $error = '';

    public function mount(): void
    {
        $this->eventId = (int) request()->query('event', 0);
        $asked = request()->query('t', []);
        $this->quantities = collect(is_array($asked) ? $asked : [])
            ->mapWithKeys(fn ($q, $id) => [(int) $id => max(0, min(50, (int) $q))])->filter()->all();
        $event = $this->event();
        if ($this->quantities === []) {
            $this->redirect($this->eventUrl($event), navigate: true);

            return;
        }
        $user = auth()->user();
        $this->buyerName = (string) $user->name;
        $this->buyerPhone = (string) $user->phone;
    }

    private function event(): Event
    {
        $event = Event::with(['ticketTypes', 'franchise.city', 'organiser'])->where('status', 'approved')->findOrFail($this->eventId);
        abort_unless(EventTicketService::onSale($event), 404);

        return $event;
    }

    private function eventUrl(Event $event): string
    {
        return $event->franchise?->city ? route('customer.events.show', [$event->franchise->city->slug, $event->slug]) : route('customer.home');
    }

    public function pay(string $method, EventTicketService $tickets)
    {
        $this->reset('error');
        $this->validate([
            'buyerName' => ['required', 'string', 'min:2', 'max:120'],
            'buyerPhone' => ['required', 'string', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/'],
        ]);

        try {
            $order = $tickets->buy(auth()->user(), $this->event(), $this->quantities, $method === 'wallet' ? 'wallet' : 'online', $this->buyerName, $this->buyerPhone);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        if ($order->payment_method === 'wallet') {
            return $this->redirectRoute('customer.events.order', $order->code, navigate: true);
        }

        try {
            $checkout = $tickets->startOnlinePayment($order);
        } catch (\Throwable $e) {
            report($e);

            // The order holds its tickets; the customer can pay from its page until the hold ends.
            return $this->redirectRoute('customer.events.order', $order->code, navigate: true);
        }
        $this->dispatch('razorpay-open', order: $checkout, done: route('customer.events.order', $order->code).'?paid=1', back: route('customer.events.order', $order->code))->self();

        return null;
    }

    public function render()
    {
        $event = $this->event();
        $quote = EventTicketService::quote($event, $this->quantities);
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $money = fn (float $v) => $symbol.number_format($v, 2);
        $walletOn = Setting::get('payment.wallet_enabled', null, array_filter(['zone_id' => $event->zone_id, 'franchise_id' => $event->franchise_id])) === '1';
        $balance = $walletOn ? app(WalletService::class)->balance(auth()->user()) : 0.0;
        $tz = app(\App\Services\TimezoneResolver::class)->timezoneFor($event->franchise);

        return view('livewire.customer.events.book', [
            'event' => $event,
            'quote' => $quote,
            'money' => $money,
            'when' => $event->starts_at->copy()->setTimezone($tz)->format('D, j M Y · g:i A'),
            'walletOn' => $walletOn && $balance >= $quote['total'],
            'balance' => $money($balance),
            'refundLine' => \App\Services\Events\EventTicketService::refundLine($event),
            'canBuy' => $quote['tickets'] > 0 && $quote['taxes_known'],
            'backUrl' => $this->eventUrl($event),
        ])->layout('components.layouts.customer', ['title' => SiteText::get('event.buy.choose'), 'indexable' => false]);
    }
}

<?php

namespace App\Livewire\Customer\Halls;

use App\Models\HallBooking;
use App\Models\Setting;
use App\Services\Halls\HallBookingService;
use App\Support\SiteText;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Function halls R2 — /halls/bookings/{code} (the customer only): where the booking stands (waiting for payment → Pay
 * now until the hold ends; waiting for the hall to accept; confirmed with the hall's phone; not accepted and refunded),
 * the date and slot, and the money: paid now, and what is due later (balance + deposit) by when.
 */
class Booking extends Component
{
    #[Locked]
    public string $code = '';

    public string $error = '';

    public function mount(string $code): void
    {
        $this->code = $code;
        $this->booking();
    }

    private function booking(): HallBooking
    {
        return HallBooking::with(['hall.franchise.city'])->where('code', $this->code)->where('customer_id', auth()->id())->firstOrFail();
    }

    public function payNow(HallBookingService $halls): void
    {
        $this->reset('error');
        $booking = $this->booking();
        try {
            $checkout = $halls->startOnlinePayment($booking);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $url = route('customer.halls.booking', $booking->code);
        $this->dispatch('razorpay-open', order: $checkout, done: $url.'?paid=1', back: $url)->self();
    }

    /** R3: the balance (and deposit) online. */
    public function payBalance(HallBookingService $halls): void
    {
        $this->reset('error');
        $booking = $this->booking();
        try {
            $checkout = $halls->startBalancePayment($booking);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $url = route('customer.halls.booking', $booking->code);
        $this->dispatch('razorpay-open', order: $checkout, done: $url.'?paid=1', back: $url)->self();
    }

    /** R3: the balance (and deposit) from the wallet, at once. */
    public function payBalanceFromWallet(HallBookingService $halls): void
    {
        $this->reset('error');
        try {
            $halls->payBalanceFromWallet($this->booking(), auth()->user());
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public string $cancelReason = '';

    public ?float $refunded = null;

    /** R3: the customer cancels; the refund shown before is what comes back. */
    public function cancelBooking(HallBookingService $halls): void
    {
        $this->reset('error');
        $this->validate(['cancelReason' => ['nullable', 'string', 'max:300']]);
        try {
            $this->refunded = $halls->customerCancel($this->booking(), auth()->user(), $this->cancelReason);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $booking = $this->booking();
        $hall = $booking->hall;
        $tz = HallBookingService::tz($hall);
        $symbol = Setting::get('locale.currency_symbol', '₹');

        return view('livewire.customer.halls.booking', [
            'booking' => $booking,
            'hall' => $hall,
            'when' => $booking->event_date->format('l, j F Y').' · '.$booking->slot_name.' ('.$booking->starts_at->copy()->setTimezone($tz)->format('g:i A').' – '.$booking->ends_at->copy()->setTimezone($tz)->format('g:i A').')',
            'holdUntil' => $booking->payment_expires_at?->copy()->setTimezone($tz)->format('g:i A'),
            'acceptBy' => $booking->accept_by?->copy()->setTimezone($tz)->format('D, j M · g:i A'),
            'money' => fn (float $v) => $symbol.number_format($v, 2),
            'hallUrl' => $hall->franchise?->city ? route('customer.halls.show', [$hall->franchise->city->slug, $hall->slug]) : null,
            'mapUrl' => $hall->lat !== null ? 'https://www.google.com/maps/search/?api=1&query='.$hall->lat.','.$hall->lng
                : 'https://www.google.com/maps/search/?api=1&query='.urlencode($hall->name.', '.$hall->address_line),
            'justPaid' => request()->boolean('paid'),
            'refundLine' => HallBookingService::refundLine($hall),
            'canCancel' => in_array($booking->status, ['awaiting_owner', 'confirmed'], true) && $booking->starts_at->isFuture(),
            'cancelQuote' => in_array($booking->status, ['awaiting_owner', 'confirmed'], true) ? HallBookingService::cancelQuote($booking) : null,
            'walletOn' => \App\Models\Setting::get('payment.wallet_enabled', null, array_filter(['zone_id' => $booking->zone_id, 'franchise_id' => $booking->franchise_id])) === '1'
                && app(\App\Services\WalletService::class)->balance(auth()->user()) >= $booking->balanceLater(),
        ])->layout('components.layouts.customer', ['title' => SiteText::get('hall.booking.title'), 'indexable' => false]);
    }
}

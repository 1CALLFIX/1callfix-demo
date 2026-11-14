<?php

namespace App\Livewire\Customer\Halls;

use App\Models\Hall;
use App\Models\HallSlot;
use App\Models\Setting;
use App\Services\Halls\HallBookingService;
use App\Services\WalletService;
use App\Support\SiteText;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Function halls R2 — /halls/book?hall=…&slot=…&date=… (signed in): change the date or slot (free or taken is shown at
 * once), the occasion, guests and contact, then the bill (hall price, GST, booking fee, total, deposit; pay now and pay
 * later: the HallBookingService quote that is charged) and Pay online (Razorpay) or from the wallet. Online: the slot
 * is held while paying; the booking page shows "waiting for the hall" once the bank confirms.
 */
class Book extends Component
{
    #[Locked]
    public int $hallId = 0;

    public string $slotId = '';
    public string $date = '';
    /** Owner 2026-11-14: days in a row (a 2-day wedding), up to Hall Settings' hall.max_days. */
    public string $days = '1';
    public string $occasion = '';
    public string $guests = '';
    public string $name = '';
    public string $phone = '';
    public string $notes = '';
    public string $error = '';

    public function mount(): void
    {
        $this->hallId = (int) request()->query('hall', 0);
        $hall = $this->hall();
        $this->slotId = (string) (int) request()->query('slot', $hall->slots->where('is_active', true)->first()?->id);
        $date = (string) request()->query('date', '');
        $this->date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : HallBookingService::dateWindow($hall)[0];
        $this->days = (string) max(1, min(HallBookingService::maxDays($hall), (int) request()->query('days', 1)));
        $user = auth()->user();
        $this->name = (string) $user->name;
        $this->phone = (string) $user->phone;
    }

    private function hall(): Hall
    {
        $hall = Hall::with(['slots', 'franchise.city'])->find($this->hallId);
        abort_unless($hall && HallBookingService::bookable($hall), 404);

        return $hall;
    }

    private function slot(Hall $hall): ?HallSlot
    {
        return $hall->slots->where('is_active', true)->firstWhere('id', (int) $this->slotId);
    }

    private function hallUrl(Hall $hall): string
    {
        return $hall->franchise?->city ? route('customer.halls.show', [$hall->franchise->city->slug, $hall->slug]) : route('customer.home');
    }

    public function pay(string $method, HallBookingService $halls)
    {
        $this->reset('error');
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/'],
            'occasion' => ['nullable', 'string', 'max:80'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $hall = $this->hall();
        $slot = $this->slot($hall);
        if ($slot === null) {
            $this->error = SiteText::get('hall.book.choose_slot');

            return null;
        }

        try {
            $booking = $halls->book(auth()->user(), $hall, $slot, $this->date, $method === 'wallet' ? 'wallet' : 'online', $this->name, $this->phone,
                ['days' => $this->dayCount($hall), 'occasion' => $this->occasion, 'guests' => $this->guests !== '' ? (int) $this->guests : null, 'notes' => $this->notes]);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        if ($booking->payment_method === 'wallet') {
            return $this->redirectRoute('customer.halls.booking', $booking->code, navigate: true);
        }

        try {
            $checkout = $halls->startOnlinePayment($booking);
        } catch (\Throwable $e) {
            report($e);

            // The booking holds its slot; the customer can pay from its page until the hold ends.
            return $this->redirectRoute('customer.halls.booking', $booking->code, navigate: true);
        }
        $this->dispatch('razorpay-open', order: $checkout, done: route('customer.halls.booking', $booking->code).'?paid=1', back: route('customer.halls.booking', $booking->code))->self();

        return null;
    }

    private function dayCount(Hall $hall): int
    {
        return max(1, min(HallBookingService::maxDays($hall), (int) $this->days));
    }

    public function render()
    {
        $hall = $this->hall();
        $slot = $this->slot($hall);
        $days = $this->dayCount($hall);
        [$first, $last] = HallBookingService::dateWindow($hall);
        $dateOk = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) === 1 && $this->date >= $first
            && Carbon::parse($this->date)->addDays($days - 1)->toDateString() <= $last;
        $free = $slot && $dateOk && HallBookingService::isFree($hall, $slot, $this->date, null, $days);
        $quote = $slot && $dateOk ? HallBookingService::quote($hall, $slot, $this->date, $days) : null;
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $money = fn (float $v) => $symbol.number_format($v, 2);
        $walletOn = Setting::get('payment.wallet_enabled', null, array_filter(['zone_id' => $hall->zone_id, 'franchise_id' => $hall->franchise_id])) === '1';
        $balance = $walletOn ? app(WalletService::class)->balance(auth()->user()) : 0.0;

        return view('livewire.customer.halls.book', [
            'hall' => $hall,
            'hallSlots' => $hall->slots->where('is_active', true)->values(),
            'chosenSlot' => $slot,
            'maxDays' => HallBookingService::maxDays($hall),
            'lastDay' => $dateOk ? Carbon::parse($this->date)->addDays($days - 1)->format('D, j M Y') : null,
            'first' => $first,
            'last' => $last,
            'dateOk' => $dateOk,
            'free' => $free,
            'quote' => $quote,
            'money' => $money,
            'dueOn' => $quote && $quote['balance_due_on'] ? Carbon::parse($quote['balance_due_on'])->format('j M Y') : null,
            'walletOn' => $walletOn && $quote && $balance >= $quote['pay_now'],
            'balance' => $money($balance),
            'refundLine' => HallBookingService::refundLine($hall),
            'canBook' => $free && $quote && $quote['taxes_known'],
            'backUrl' => $this->hallUrl($hall),
        ])->layout('components.layouts.customer', ['title' => SiteText::get('hall.book.title'), 'indexable' => false]);
    }
}

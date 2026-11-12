<?php

namespace App\Livewire\Customer\Hotels;

use App\Models\Accommodation;
use App\Models\HotelRatePlan;
use App\Models\HotelRoomType;
use App\Models\Setting;
use App\Services\Hotels\HotelBookingService;
use App\Services\WalletService;
use App\Support\HotelPricing;
use App\Support\SiteText;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Hotels H2b — /hotels/book: the stay chosen on the hotel page (hotel, room, plan, dates, rooms, adults from the
 * link), the price breakdown from HotelPricing, the guest's name and phone, an optional business GST number for a
 * GST invoice, and Pay. Online opens Razorpay at once; the booking is confirmed only when the bank's capture arrives
 * (HotelBookingService::markPaid). Wallet pays at once when it is switched on and holds enough.
 */
class Book extends Component
{
    #[Url(as: 'hotel')] #[Locked]
    public int $hotelId = 0;

    #[Url(as: 'room')] #[Locked]
    public int $roomId = 0;

    #[Url(as: 'plan')] #[Locked]
    public int $planId = 0;

    #[Url(as: 'in')] #[Locked]
    public string $checkIn = '';

    #[Url(as: 'out')] #[Locked]
    public string $checkOut = '';

    #[Url(as: 'rooms')] #[Locked]
    public int $rooms = 1;

    #[Url(as: 'adults')] #[Locked]
    public int $adults = 2;

    #[Url(as: 'children')] #[Locked]
    public int $children = 0;

    /** Step 3: the cheaper no-refund price for this room. */
    #[Url(as: 'nr')] #[Locked]
    public bool $nr = false;

    /** Step 3: "Change dates" — the code of the guest's booking this one replaces. */
    #[Url(as: 'replace')] #[Locked]
    public string $replace = '';

    /** Hotels step 2: a coupon (online / wallet only). Only the code is kept here; every amount is worked out on the server. */
    public string $couponInput = '';

    #[Locked]
    public string $appliedCoupon = '';

    public string $couponMessage = '';

    public string $guestName = '';
    public string $guestPhone = '';
    public string $requests = '';
    public bool $business = false;
    public string $gstNumber = '';
    public string $businessName = '';

    public string $error = '';

    public function mount(): void
    {
        $this->stay(); // 404 for a hotel / room that is not bookable
        $user = auth()->user();
        $this->guestName = (string) $user->name;
        $this->guestPhone = (string) $user->phone;
    }

    /** @return array{0: Accommodation, 1: HotelRoomType, 2: HotelRatePlan} */
    /** The guest's own booking being replaced by "Change dates" (null when this is a new stay). */
    private function replacing(): ?\App\Models\HotelReservation
    {
        return $this->replace === '' ? null
            : \App\Models\HotelReservation::where('code', $this->replace)->where('customer_id', auth()->id())->first();
    }

    private function stay(): array
    {
        $hotel = Accommodation::bookable()->with(['franchise', 'accommodationType'])->findOrFail($this->hotelId);
        $room = HotelRoomType::where('accommodation_id', $hotel->id)->where('is_active', true)->with('accommodation')->findOrFail($this->roomId);
        $plan = HotelRatePlan::where('hotel_room_type_id', $room->id)->where('is_active', true)->findOrFail($this->planId);

        return [$hotel, $room, $plan];
    }

    public function pay(string $method, HotelBookingService $bookings)
    {
        $this->reset('error');
        $this->validate([
            'guestName' => ['required', 'string', 'min:2', 'max:120'],
            'guestPhone' => ['required', 'string', 'max:20', 'regex:/^[0-9+][0-9 \-]*$/'],
            'requests' => ['nullable', 'string', 'max:500'],
            'gstNumber' => [$this->business ? 'required' : 'nullable', 'string', 'regex:/^[0-9]{2}[A-Za-z0-9]{13}$/'],
            'businessName' => [$this->business ? 'required' : 'nullable', 'string', 'max:120'],
        ], [
            'guestName.required' => SiteText::get('hotel.book.need_name'),
            'gstNumber.regex' => SiteText::get('hotel.book.bad_gst'),
            'gstNumber.required' => SiteText::get('hotel.book.bad_gst'),
        ]);

        [$hotel, $room, $plan] = $this->stay();
        try {
            $reservation = $bookings->book(auth()->user(), $hotel, $room, $plan, $this->checkIn, $this->checkOut, $this->rooms, $this->adults, [
                'guest_name' => $this->guestName, 'guest_phone' => $this->guestPhone, 'special_requests' => $this->requests,
                'gst_number' => $this->business ? $this->gstNumber : null, 'business_name' => $this->business ? $this->businessName : null,
            ], $method === 'wallet' ? 'wallet' : 'online', $this->appliedCoupon !== '' ? $this->appliedCoupon : null, max(0, $this->children), [
                'non_refundable' => $this->nr, 'replaces' => $this->replacing(),
            ]);
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        if ($reservation->payment_method === 'wallet') {
            return $this->redirectRoute('customer.hotels.booking', $reservation->code, navigate: true);
        }

        try {
            $checkout = $bookings->startOnlinePayment($reservation);
        } catch (\Throwable $e) {
            report($e);

            // The booking holds its rooms; the customer can pay from its page until the hold ends.
            return $this->redirectRoute('customer.hotels.booking', $reservation->code, navigate: true);
        }
        $this->dispatch('razorpay-open', order: $checkout, done: route('customer.hotels.booking', $reservation->code).'?paid=1', back: route('customer.hotels.booking', $reservation->code))->self();

        return null;
    }

    public function applyCoupon(HotelBookingService $bookings): void
    {
        $this->couponMessage = '';
        $code = strtoupper(trim($this->couponInput));
        if ($code === '') {
            return;
        }
        [$hotel, $room, $plan] = $this->stay();
        $q = HotelPricing::quote($room, $plan, $this->checkIn, $this->checkOut, max(1, $this->rooms), $this->nr);
        [$discount, $message] = $bookings->previewCoupon(auth()->user(), $hotel, $q['room_total'], 'online', $code);
        if ($message !== null) {
            $this->appliedCoupon = '';
            $this->couponMessage = $message;

            return;
        }
        $this->appliedCoupon = $code;
        $this->couponMessage = SiteText::get('hotel.book.coupon_applied', ['code' => $code]);
    }

    public function removeCoupon(): void
    {
        $this->reset('appliedCoupon', 'couponInput', 'couponMessage');
    }

    public function render()
    {
        [$hotel, $room, $plan] = $this->stay();
        $q = HotelPricing::quote($room, $plan, $this->checkIn, $this->checkOut, max(1, $this->rooms), $this->nr);
        // The coupon is worked out again on every screen (it can run out meanwhile); booking checks it once more.
        if ($this->appliedCoupon !== '') {
            [$discount, $message] = app(HotelBookingService::class)->previewCoupon(auth()->user(), $hotel, $q['room_total'], 'online', $this->appliedCoupon);
            if ($message !== null) {
                $this->appliedCoupon = '';
                $this->couponMessage = $message;
            } else {
                $q = HotelPricing::withDiscount($q, $discount);
            }
        }
        $symbol = Setting::get('locale.currency_symbol', '₹');
        $money = fn (float $v) => $symbol.number_format($v, 2);
        $scope = array_filter(['zone_id' => $hotel->zone_id, 'franchise_id' => $hotel->franchise_id]);
        $walletOn = Setting::get('payment.wallet_enabled', null, $scope) === '1';
        $balance = $walletOn ? app(WalletService::class)->balance(auth()->user()) : 0.0;
        $until = HotelPricing::freeCancelUntil($hotel, $this->checkIn);
        $tz = app(\App\Services\TimezoneResolver::class)->timezoneFor($hotel->franchise);

        return view('livewire.customer.hotels.book', [
            'hotel' => $hotel, 'room' => $room, 'plan' => $plan, 'quote' => $q,
            'nights' => count($q['nights']),
            'money' => $money,
            'walletOn' => $walletOn && $balance >= $q['total'],
            'balance' => $money($balance),
            'replacing' => $this->replacing(),
            'cancel' => $this->nr ? SiteText::get('hotel.book.non_refundable') : ($until === null ? SiteText::get('hotel.free_cancel_always')
                : ($until->isFuture() ? SiteText::get('hotel.free_cancel_until', ['time' => $until->setTimezone($tz)->format('j M, g:i A')]) : SiteText::get('hotel.book.no_free_cancel'))),
            'canBook' => $q['available'] && $q['taxes_known'],
            'couponsOn' => \App\Services\Coupons\CouponSettings::available(),
        ])->layout('components.layouts.customer', ['title' => SiteText::get('hotel.book.title'), 'indexable' => false]);
    }
}

<?php

namespace App\Livewire\Customer\Concerns;

use App\Exceptions\CouponEntryBlockedException;
use App\Services\Coupons\CouponEntryGate;
use App\Services\Coupons\CouponQuoteService;
use App\Services\Coupons\CouponSettings;
use App\Services\Payments\OnlinePaymentGuard;

/**
 * The customer coupon field, shared by the wizard, cart and checkout. The screen only holds the typed code and
 * whether the customer pressed Apply; every number it shows (full price, discount, payable) is computed by
 * CouponQuoteService at render time from the server's own cascade, so the client never supplies an amount.
 * Pressing Apply and placing the booking each pass the Super Admin rate limit (CouponEntryGate); a rendered
 * quote does not.
 *
 * The using component supplies: couponSurface(), couponItems(), couponLocation(), couponPaymentMethod().
 */
trait HasCouponEntry
{
    public string $couponCode = '';

    public bool $couponApplied = false;

    /** A refusal that happened before the engine judged the code (surface off, rate limit). Customer-safe by construction. */
    public string $couponGateMessage = '';

    abstract protected function couponSurface(): string;

    /** @return array<int, array{service: \App\Models\Service, quantity: int}> */
    abstract protected function couponItems(): array;

    /** @return array{0: ?int, 1: ?int} [franchise id, zone id] the price and the coupon are judged for */
    abstract protected function couponLocation(): array;

    abstract protected function couponPaymentMethod(): string;

    public function updatedCouponCode(): void
    {
        $this->couponApplied = false;
        $this->couponGateMessage = '';
    }

    public function applyCoupon(): void
    {
        $this->couponGateMessage = '';
        $this->couponApplied = false;

        if (trim($this->couponCode) === '') {
            return;
        }

        try {
            CouponEntryGate::attempt(auth()->user(), $this->couponSurface(), request()->ip());
        } catch (CouponEntryBlockedException $e) {
            $this->couponGateMessage = $e->getMessage();

            return;
        }

        $this->couponApplied = true;
        $this->couponApplied();
    }

    /** Hook for a component that wants to remember an applied code (the cart hands it on to checkout). */
    protected function couponApplied(): void
    {
    }

    /**
     * The code to send with the booking: null when blank. Goes through the gate, so a surface switched off or an
     * exhausted rate limit stops the booking with a customer-safe message (a RuntimeException the screen shows).
     */
    protected function couponCodeForBooking(): ?string
    {
        $code = trim($this->couponCode);
        if ($code === '') {
            return null;
        }

        CouponEntryGate::attempt(auth()->user(), $this->couponSurface(), request()->ip());

        return $code;
    }

    /** @return array{enabled: bool, quote: ?array, message: string, applied: bool} for the coupon box */
    protected function couponView(): array
    {
        $enabled = CouponSettings::entryAvailable($this->couponSurface());
        $view = ['enabled' => $enabled, 'quote' => null, 'message' => $this->couponGateMessage, 'applied' => $this->couponApplied];

        if (! $enabled || ! $this->couponApplied || trim($this->couponCode) === '') {
            return $view;
        }

        [$franchiseId, $zoneId] = $this->couponLocation();
        if (! $franchiseId || ! $zoneId) {
            $view['message'] = 'Choose your location first.';

            return $view;
        }

        $quote = app(CouponQuoteService::class)->quote(
            auth()->user(), $franchiseId, $zoneId, $this->couponItems(), $this->couponPaymentMethod(), trim($this->couponCode),
        );
        $view['quote'] = $quote;
        $view['message'] = $quote['eligible'] ? '' : (string) $quote['message'];

        return $view;
    }

    /** True when the customer has typed a code but is paying in a way the offer does not apply to. */
    protected function couponNeedsOnline(): bool
    {
        return trim($this->couponCode) !== '' && ! OnlinePaymentGuard::isOnline($this->couponPaymentMethod());
    }
}

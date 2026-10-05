<?php

namespace App\Exceptions;

use App\Services\Coupons\PromotionResult;
use App\Services\Payments\OnlinePaymentGuard;

/**
 * A coupon was supplied but cannot be applied. `reason` is the precise internal code (see
 * App\Services\Coupons\PromotionResult) and `detail` the precise internal wording, for logs, audit and admin
 * diagnostics ONLY — they must never be sent to a customer, because a specific reason (expired, wrong city,
 * budget gone...) reveals facts about a coupon the customer may not own.
 *
 * The exception's own message IS the customer text, so every surface that echoes getMessage() (API, Form
 * Request, Livewire, JSON, Flutter) is safe by construction: one of exactly two sentences.
 */
class CouponException extends \RuntimeException
{
    public const GENERIC_MESSAGE = PromotionResult::GENERIC_MESSAGE;

    public const ONLINE_ONLY_MESSAGE = OnlinePaymentGuard::MESSAGE;

    /** The precise internal wording. Never shown to a customer. */
    public readonly string $detail;

    public function __construct(public readonly string $reason, string $detail = '')
    {
        $this->detail = $detail;

        parent::__construct(self::customerMessageFor($reason));
    }

    /** Payment-eligibility rejections say so; every other reason gets the one generic sentence. */
    public static function customerMessageFor(string $reason): string
    {
        return $reason === 'online_payment_required' ? self::ONLINE_ONLY_MESSAGE : self::GENERIC_MESSAGE;
    }

    /** @return array{message: string} what any customer-facing surface (web, API, Flutter) may show. */
    public function customerPayload(): array
    {
        return ['message' => $this->getMessage()];
    }
}

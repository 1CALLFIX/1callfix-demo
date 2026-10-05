<?php

namespace App\Exceptions;

/**
 * A coupon was supplied but cannot be applied. `reason` is the precise internal code (see
 * App\Services\Coupons\PromotionResult) for logs, audit and admin diagnostics ONLY — it must never be sent
 * to a customer, because a specific reason (expired, wrong city, budget gone...) reveals facts about a
 * coupon the customer may not own. Clients get customerPayload(): the same shape and text for every reason.
 */
class CouponException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    /** @return array{message: string} what any customer-facing surface (web, API, Flutter) may show. */
    public function customerPayload(): array
    {
        return ['message' => $this->getMessage()];
    }
}

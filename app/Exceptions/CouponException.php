<?php

namespace App\Exceptions;

/**
 * A coupon was supplied but cannot be applied. `reason` is a stable code
 * (see App\Services\Coupons\PromotionResult) clients can localise; the
 * message is customer-safe and never reveals other customers' facts.
 */
class CouponException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

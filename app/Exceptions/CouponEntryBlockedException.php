<?php

namespace App\Exceptions;

/**
 * A coupon entry attempt was refused BEFORE the engine judged it: the surface is switched off, coupons are off,
 * or the customer / IP has used up the attempt limit. The message is already customer-safe; `status` is the HTTP
 * status an API surface should answer with.
 */
class CouponEntryBlockedException extends \RuntimeException
{
    public const RATE_LIMITED_MESSAGE = 'Too many coupon attempts. Please wait a moment and try again.';

    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self(CouponException::GENERIC_MESSAGE, 422);
    }

    public static function rateLimited(): self
    {
        return new self(self::RATE_LIMITED_MESSAGE, 429);
    }
}

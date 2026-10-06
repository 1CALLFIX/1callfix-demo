<?php

namespace App\Exceptions;

/**
 * The payment gateway refused or failed a call made on a customer's behalf (opening an order). The exception's own
 * message is the customer text, so every surface that echoes getMessage() (web, API, Flutter) is safe by
 * construction. The gateway's wording, receipt ids and response body live in `detail` and in the log only.
 */
class PaymentGatewayException extends \RuntimeException
{
    public const CUSTOMER_MESSAGE = 'We could not start the payment right now. Please try again in a moment.';

    public function __construct(public readonly string $detail)
    {
        parent::__construct(self::CUSTOMER_MESSAGE);
    }
}

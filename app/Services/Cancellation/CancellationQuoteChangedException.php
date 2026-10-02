<?php

namespace App\Services\Cancellation;

/** The quote the customer confirmed no longer matches the live figures (status/clock/charge changed). Carries the fresh quote. */
class CancellationQuoteChangedException extends \RuntimeException
{
    public function __construct(public readonly array $quote)
    {
        parent::__construct('The cancellation amount has changed. Please review the new amount and confirm again.');
    }
}

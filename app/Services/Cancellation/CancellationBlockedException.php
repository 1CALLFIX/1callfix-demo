<?php

namespace App\Services\Cancellation;

/** REF 1CF-CANCEL-POLICY-001 — the customer may not cancel right now (mid-work lock, dispute open, ...). */
class CancellationBlockedException extends \RuntimeException
{
    public function __construct(public readonly array $decision)
    {
        parent::__construct($decision['message']);
    }
}

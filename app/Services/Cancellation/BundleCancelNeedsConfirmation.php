<?php

namespace App\Services\Cancellation;

/**
 * REF 1CF-CANCEL-POLICY-001 step 8 — a bundle cancel that would only partly succeed (some visits are locked or carry a
 * charge) is never done silently: the customer must first see which visits go and which stay, and confirm that exact set.
 */
class BundleCancelNeedsConfirmation extends \RuntimeException
{
    public function __construct(public readonly array $preview)
    {
        parent::__construct('Some visits in this bundle cannot be cancelled. Review which visits will be cancelled and which will stay, then confirm.');
    }
}

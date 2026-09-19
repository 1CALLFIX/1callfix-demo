<?php

namespace App\Exceptions;

/**
 * Thrown when a consume would take a quantity-limited entitlement below zero.
 * A RuntimeException on purpose: every existing caller already treats a
 * RuntimeException from the Plan Engine as "refused, nothing written".
 */
class InsufficientEntitlementException extends \RuntimeException
{
}

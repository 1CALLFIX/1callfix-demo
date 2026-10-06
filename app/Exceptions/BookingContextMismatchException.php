<?php

namespace App\Exceptions;

/**
 * Thrown by CreateBookingAction when a caller-supplied franchise / zone / address does not match what the
 * server derives from the customer's own address. The caller is never trusted for ownership context
 * (hardening §F): a mismatch is rejected, not silently corrected.
 */
class BookingContextMismatchException extends \RuntimeException
{
    public function __construct(string $detail)
    {
        parent::__construct('This booking location is not valid. '.$detail);
    }
}

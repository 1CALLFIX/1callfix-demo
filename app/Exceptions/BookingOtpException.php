<?php

namespace App\Exceptions;

/**
 * Phase E5 — raised by BookingOtpService::verifyOrFail() when a booking
 * start / completion OTP does not verify. Extends \RuntimeException so
 * every existing `catch (\RuntimeException $e)` (DispatchController,
 * WorkerJobController, the FSM tests) keeps catching it and the HTTP layer
 * still answers 409 with the message.
 *
 * `countsAsAttempt` is the one extra bit the calling Action needs: a plain
 * wrong code must burn one attempt off the cap, and that increment has to
 * be committed even though the Action's own transaction rolls back when
 * this propagates — so the Action persists it separately, outside the
 * transaction, only when this flag is true. Expired / already-used /
 * cap-exhausted failures set it false (nothing to count).
 */
class BookingOtpException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $countsAsAttempt = false)
    {
        parent::__construct($message);
    }
}

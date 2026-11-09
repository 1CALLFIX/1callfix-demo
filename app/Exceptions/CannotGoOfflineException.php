<?php

namespace App\Exceptions;

/**
 * Owner 2026-11-09: a rider carrying a delivery, a service partner on the way to or working on a job, cannot go
 * offline until it is done. The message is shown to them as it is.
 */
class CannotGoOfflineException extends \RuntimeException
{
}

<?php

namespace App\Exceptions;

use RuntimeException;

/** A provider may not go online right now; the message is shown to them as it is. */
class CannotGoOnlineException extends RuntimeException
{
}

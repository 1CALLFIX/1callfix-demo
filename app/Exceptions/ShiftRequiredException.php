<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when shifts are required and a provider tries to go online outside any shift they have chosen. */
class ShiftRequiredException extends RuntimeException
{
}

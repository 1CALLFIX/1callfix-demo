<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Signup was asked to provision an identifier (phone or email) that already
 * belongs to a fully-registered account. The caller surfaces a "please log
 * in instead" message — never a silent overwrite of the existing row.
 */
class AccountAlreadyExistsException extends RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct("An account with this {$field} already exists.");
    }
}

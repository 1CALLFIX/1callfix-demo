<?php

namespace App\Exceptions;

/**
 * Thrown by WalletService when a frozen wallet is asked for a movement
 * WalletFreezePolicy refuses. A RuntimeException so every existing caller
 * that already maps RuntimeException to a user-facing message (422 / flash)
 * keeps working unchanged.
 */
class WalletFrozenException extends \RuntimeException
{
    public function __construct(string $message = 'This wallet is frozen. Please contact support.')
    {
        parent::__construct($message);
    }
}

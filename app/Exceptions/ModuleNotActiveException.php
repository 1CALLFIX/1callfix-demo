<?php

namespace App\Exceptions;

/**
 * Thrown by a module's own order-creation entry point (see
 * CreateBookingAction) when ModuleActivationService::isActive() reports the
 * module as not active for the requesting scope — either because it isn't
 * implemented at all (Phase 22 audit §5's "unimplemented module must never
 * appear as usable merely because its key exists"), or because an admin
 * explicitly turned it off at some level of the Country/City/Zone/
 * Franchise chain.
 */
class ModuleNotActiveException extends \RuntimeException
{
    public function __construct(string $moduleCode)
    {
        parent::__construct("The '{$moduleCode}' module is not active for this location.");
    }
}

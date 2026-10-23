<?php

namespace App\Exceptions;

/**
 * A parcel cannot be priced: pricing is not configured in that scope, or the parcel is outside the admin's
 * distance / weight limits. The message is customer-safe.
 */
class ParcelQuoteException extends \RuntimeException
{
}

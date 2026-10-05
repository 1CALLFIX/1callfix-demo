<?php

namespace App\Exceptions;

/**
 * A benefit (coupon, discount, offer, credit...) was attempted without a fully online payment.
 * Extends LogicException so the model-level "cannot switch to cash" guard keeps its original exception family.
 */
class OnlinePaymentRequiredException extends \LogicException
{
}

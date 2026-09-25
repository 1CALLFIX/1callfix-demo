<?php

namespace App\Services\Referrals;

/** A referral code was typed at signup but is not valid (unknown, self, blocked or inactive referrer). */
class InvalidReferralCode extends \RuntimeException
{
}

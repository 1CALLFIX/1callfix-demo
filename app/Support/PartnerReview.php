<?php

namespace App\Support;

/** kyc_status values that mean "documents sent, nobody has decided yet" — the Needs review list on Admin → Partners. */
final class PartnerReview
{
    public const STATUSES = ['pending', 'submitted', 'under_review'];
}

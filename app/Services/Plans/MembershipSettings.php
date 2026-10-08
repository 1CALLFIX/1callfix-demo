<?php

namespace App\Services\Plans;

use App\Models\Setting;

/**
 * Admin-controlled membership knobs (Plans & Memberships -> Membership settings). A saved setting wins; the
 * config/env value is only the fallback when nothing has been saved. Nothing about a membership's money or
 * quantities lives here: price, validity, quantities and values are edited per plan / entitlement, and the visit
 * charge a free cancellation waives is always `cancellation.visit_fee_value`.
 */
class MembershipSettings
{
    public const REMINDER_DAYS = 'membership.expiry_reminder_days';

    public const PRIORITY_MULTIPLIER = 'membership.priority_batch_multiplier';

    public static function expiryReminderDays(): int
    {
        return max(1, (int) Setting::get(self::REMINDER_DAYS, config('membership.expiry_reminder_days', 14)));
    }

    public static function priorityBatchMultiplier(): int
    {
        return max(1, (int) Setting::get(self::PRIORITY_MULTIPLIER, config('membership.priority_batch_multiplier', 2)));
    }
}

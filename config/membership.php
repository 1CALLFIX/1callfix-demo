<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Expiry reminder window
    |--------------------------------------------------------------------------
    | How many days before current_period_end a customer membership gets its
    | one "ending soon" reminder. Sent from RenewalService::sendExpiryReminders(),
    | inside the existing hourly plans:renew-due pass.
    */
    'expiry_reminder_days' => (int) env('MEMBERSHIP_EXPIRY_REMINDER_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Priority Based Service
    |--------------------------------------------------------------------------
    | A booking flagged is_priority (its customer holds a membership with a
    | usable `priority` entitlement) asks DispatchService::findCandidates() for
    | this many TIMES the normal offer batch per round. Only the size of the
    | ask changes — every eligibility, zone, location-freshness and skill rule
    | still runs inside findCandidates(). It is preference, never a guarantee
    | of immediate service. 1 disables the preference.
    */
    'priority_batch_multiplier' => max(1, (int) env('MEMBERSHIP_PRIORITY_BATCH_MULTIPLIER', 2)),

];

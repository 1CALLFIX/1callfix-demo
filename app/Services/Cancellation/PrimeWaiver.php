<?php

namespace App\Services\Cancellation;

use App\Models\Booking;
use App\Models\Subscription;
use App\Models\User;

/**
 * REF 1CF-CANCEL-POLICY-001 — does the customer hold an active plan whose "waives cancellation visit charges"
 * toggle is on? When it is, the en-route and visit/inspection charges are not levied. The toggle lives on the
 * plan form (Super Admin); off by default.
 */
class PrimeWaiver
{
    public function covers(Booking $booking): bool
    {
        if (! $booking->customer_id) {
            return false;
        }

        return Subscription::where('subscribable_type', User::class)
            ->where('subscribable_id', $booking->customer_id)
            ->whereIn('status', ['active', 'grace_period'])
            ->whereHas('plan', fn ($q) => $q->where('waives_cancellation_visit_charges', true))
            ->exists();
    }
}

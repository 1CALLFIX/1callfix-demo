<?php

namespace App\Services;

use App\Models\Franchise;
use Illuminate\Support\Facades\DB;

class OrderCodeService
{
    /**
     * Generate the next booking code for a franchise, e.g. "NLR-2907-00000001".
     *
     * Format: {FRANCHISE_CODE}-{DDMM}-{8-digit sequence, zero-padded}
     * The sequence resets to 1 at the start of each day, per franchise.
     *
     * Uses MySQL's "INSERT ... ON DUPLICATE KEY UPDATE ... LAST_INSERT_ID()" trick
     * to atomically increment the counter even under concurrent booking creation —
     * a naive "SELECT MAX(...) + 1" approach can produce duplicate codes when two
     * bookings are created in the same instant, this cannot.
     */
    public function generate(Franchise $franchise): string
    {
        if (empty($franchise->code)) {
            throw new \RuntimeException(
                "Franchise [{$franchise->id}] '{$franchise->name}' has no `code` set. " .
                "Assign a short code (e.g. 'NLR') before bookings can be created for it."
            );
        }

        $today = now()->toDateString();

        // Atomic upsert + increment in one statement.
        DB::statement(
            'INSERT INTO booking_sequences (franchise_id, sequence_date, last_number, created_at, updated_at)
             VALUES (?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_at = NOW()',
            [$franchise->id, $today]
        );

        $sequenceNumber = DB::getPdo()->lastInsertId();
        // Note: LAST_INSERT_ID(expr) sets the session's last-insert-id to our computed
        // value, so lastInsertId() here returns the up-to-date counter, not the row id.

        $datePart = now()->format('dm'); // e.g. "2907" for July 29

        return sprintf(
            '%s-%s-%08d',
            strtoupper($franchise->code),
            $datePart,
            $sequenceNumber
        );
    }
}

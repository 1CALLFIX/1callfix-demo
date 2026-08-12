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

        // MySQL (production) path is byte-for-byte unchanged: atomic upsert
        // + increment in one statement, no explicit lock needed.
        //
        // Any other driver (sqlite — used by the automated test suite and
        // the QA data factory, neither of which run against MySQL) has no
        // equivalent to LAST_INSERT_ID(expr)/ON DUPLICATE KEY UPDATE, so it
        // uses this codebase's own dominant race-safety convention instead
        // (DB::transaction + lockForUpdate, exactly what AcceptBookingAction/
        // CompleteBookingAction/ServiceMatchingJob already do) — same
        // atomicity guarantee, same output contract (sequential per
        // franchise per day), different mechanism. This was previously the
        // one thing blocking the QA data factory (which creates real
        // bookings through CreateBookingAction) from running on anything
        // but a real MySQL database.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'INSERT INTO booking_sequences (franchise_id, sequence_date, last_number, created_at, updated_at)
                 VALUES (?, ?, LAST_INSERT_ID(1), NOW(), NOW())
                 ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_at = NOW()',
                [$franchise->id, $today]
            );

            // Note: LAST_INSERT_ID(expr) sets the session's last-insert-id to our computed
            // value, so lastInsertId() here returns the up-to-date counter, not the row id.
            $sequenceNumber = DB::getPdo()->lastInsertId();
        } else {
            $sequenceNumber = DB::transaction(function () use ($franchise, $today) {
                $row = DB::table('booking_sequences')
                    ->where('franchise_id', $franchise->id)
                    ->where('sequence_date', $today)
                    ->lockForUpdate()
                    ->first();

                $next = ($row->last_number ?? 0) + 1;

                if ($row) {
                    DB::table('booking_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);
                } else {
                    DB::table('booking_sequences')->insert([
                        'franchise_id' => $franchise->id, 'sequence_date' => $today,
                        'last_number' => $next, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                return $next;
            });
        }

        $datePart = now()->format('dm'); // e.g. "2907" for July 29

        return sprintf(
            '%s-%s-%08d',
            strtoupper($franchise->code),
            $datePart,
            $sequenceNumber
        );
    }
}

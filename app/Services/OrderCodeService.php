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
        $sequenceNumber = $this->incrementSequence('booking_sequences', $franchise);

        return sprintf('%s-%s-%08d', strtoupper($franchise->code), now()->format('dm'), $sequenceNumber);
    }

    /**
     * Phase 22.4 (Parcel) — a separate counter (own table, own sequence),
     * not a shared one with Service: reusing `booking_sequences` would mean
     * Parcel and Service orders draw from the same per-franchise-per-day
     * number pool, which is still unique but conflates two conceptually
     * different order streams under one counter. The PCL segment also
     * means a Parcel code can never collide in *shape* with a Service code
     * even if the numeric part matched by coincidence.
     *
     * Service's own generate() above is completely untouched — same
     * table, same format, same output for every existing/new Service
     * booking either way.
     */
    public function generateForParcel(Franchise $franchise): string
    {
        $sequenceNumber = $this->incrementSequence('parcel_order_sequences', $franchise);

        return sprintf('%s-PCL-%s-%08d', strtoupper($franchise->code), now()->format('dm'), $sequenceNumber);
    }

    /** Phase 22.6 (Taxi) -- own counter, own table, `-TXI-` segment. Same reasoning as generateForParcel() above. */
    public function generateForTaxi(Franchise $franchise): string
    {
        $sequenceNumber = $this->incrementSequence('taxi_ride_sequences', $franchise);

        return sprintf('%s-TXI-%s-%08d', strtoupper($franchise->code), now()->format('dm'), $sequenceNumber);
    }

    /**
     * The genuinely shared, concurrency-safe primitive both formats above
     * are built on — extracted here rather than duplicated, since this is
     * the one part (atomic per-franchise-per-day increment) that's
     * actually identical between them; the code FORMAT itself deliberately
     * stays a decision each public method above makes on its own.
     *
     * @param  string  $table  'booking_sequences' or 'parcel_order_sequences' — identical shape (franchise_id, sequence_date, last_number)
     */
    private function incrementSequence(string $table, Franchise $franchise): int
    {
        if (empty($franchise->code)) {
            throw new \RuntimeException(
                "Franchise [{$franchise->id}] '{$franchise->name}' has no `code` set. " .
                "Assign a short code (e.g. 'NLR') before orders can be created for it."
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
        // franchise per day), different mechanism.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                "INSERT INTO {$table} (franchise_id, sequence_date, last_number, created_at, updated_at)
                 VALUES (?, ?, LAST_INSERT_ID(1), NOW(), NOW())
                 ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1), updated_at = NOW()",
                [$franchise->id, $today]
            );

            // Note: LAST_INSERT_ID(expr) sets the session's last-insert-id to our computed
            // value, so lastInsertId() here returns the up-to-date counter, not the row id.
            return (int) DB::getPdo()->lastInsertId();
        }

        return DB::transaction(function () use ($table, $franchise, $today) {
            $row = DB::table($table)
                ->where('franchise_id', $franchise->id)
                ->where('sequence_date', $today)
                ->lockForUpdate()
                ->first();

            $next = ($row->last_number ?? 0) + 1;

            if ($row) {
                DB::table($table)->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);
            } else {
                DB::table($table)->insert([
                    'franchise_id' => $franchise->id, 'sequence_date' => $today,
                    'last_number' => $next, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $next;
        });
    }
}

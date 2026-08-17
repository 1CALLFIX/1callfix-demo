<?php

namespace App\Services;

use App\Models\RentalReservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * RENTAL MODULE IMPLEMENTATION -- the concurrency-safety core for the
 * shared Vehicle/Equipment engine, the counterpart to
 * `PropertyAvailabilityService` for the two new rental types.
 *
 * Property's own model (sparse `property_availabilities` rows, one per
 * calendar day) works because its real DB-level backstop is a
 * `unique(['property_id', 'date'])` constraint -- but that only makes
 * sense at day granularity. Vehicle/Equipment need hourly-capable ranges
 * (`starts_at`/`ends_at` are DATETIME, not DATE), and MySQL has no native
 * exclusion constraint for arbitrary overlapping datetime ranges the way
 * Postgres' `EXCLUDE USING gist` does -- so a per-day sparse table isn't
 * the right backstop here, and a naive "check existing rows, then insert"
 * is exactly the race the mission brief warns against ("do not pretend a
 * simple application-level check-then-insert is concurrency-safe").
 *
 * The real backstop used here instead: **lock the rentable item's own row**
 * (`SELECT ... FOR UPDATE` against `vehicles`/`equipment_items`) before
 * checking for an overlapping active reservation. This serializes EVERY
 * concurrent reservation attempt against the same item -- including the
 * very first one ever made for it, where `lockForUpdate()` on the
 * (currently empty) `rental_reservations` rows would find nothing to lock
 * at all. Two concurrent transactions both calling `reserveRange()` for
 * the same item: the second one blocks on the row lock until the first
 * commits or rolls back, then re-reads the now-fresh reservation set and
 * correctly detects the conflict (or lack of one). This is a standard,
 * real concurrency-safety pattern (lock the parent resource), not a
 * simplification of Property's own design -- the two rental types
 * genuinely need different mechanisms because their granularity differs.
 *
 * `reserveRange()` MUST be called inside the caller's own `DB::transaction()`
 * -- same contract `PropertyAvailabilityService::reserveDates()` documents
 * for itself -- so a conflict rolls back the whole reservation attempt,
 * not just the lock.
 */
class RentalAvailabilityService
{
    /**
     * Read-only check -- for browse/quote, NOT the authoritative safety
     * check (mirrors PropertyAvailabilityService::isAvailable()'s own
     * documented limitation: a "looks free" here can always be raced by a
     * real reservation attempt between this call and reserveRange()).
     */
    public function isAvailable(Model $rentable, Carbon $start, Carbon $end, ?int $excludeReservationId = null): bool
    {
        return ! $this->overlapQuery($rentable, $start, $end, $excludeReservationId)->exists();
    }

    /**
     * The real, transactional, concurrency-safe check. Throws if the
     * requested range conflicts with any existing non-cancelled reservation
     * for this exact item. Pass `$excludeReservationId` when re-checking an
     * existing reservation's own row (e.g. ExtendRentalReservationAction).
     *
     * @throws \RuntimeException if the range conflicts with an existing reservation
     */
    public function reserveRange(Model $rentable, Carbon $start, Carbon $end, ?int $excludeReservationId = null): void
    {
        // Step 1: lock the rentable item's own row -- see class docblock.
        // This is the real concurrency-safety mechanism (there is no
        // per-range DB constraint to backstop a naive check like
        // property_availabilities' UNIQUE constraint does for Property).
        $rentable->newQuery()->whereKey($rentable->getKey())->lockForUpdate()->firstOrFail();

        // Step 2: with the item locked, any concurrent transaction for the
        // SAME item is now blocked until this one commits/rolls back, so
        // this overlap check is safe to perform as a plain read.
        if ($this->overlapQuery($rentable, $start, $end, $excludeReservationId)->exists()) {
            throw new \RuntimeException('This item is not available for the requested time range.');
        }
    }

    private function overlapQuery(Model $rentable, Carbon $start, Carbon $end, ?int $excludeReservationId)
    {
        return RentalReservation::query()
            ->where('rentable_type', get_class($rentable))
            ->where('rentable_id', $rentable->getKey())
            ->where('status', '!=', 'cancelled')
            ->when($excludeReservationId, fn ($q) => $q->where('id', '!=', $excludeReservationId))
            // Half-open [start, end) overlap test, same convention
            // PropertyAvailabilityService::dateRange() uses.
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }
}

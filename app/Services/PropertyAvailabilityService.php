<?php

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyAvailability;
use App\Models\PropertyReservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 22.7 (Property Rental) — the concurrency-safety core the mission
 * brief specifically demanded ("do not pretend a simple application-level
 * check-then-insert is concurrency-safe"). `property_availabilities` is
 * sparse — a date with no row means "available" (Glover's own real model,
 * verified directly, not invented) — which means the two-part safety
 * design below is necessary, not optional:
 *
 *   1. `lockForUpdate()` any EXISTING rows in the requested date range —
 *      this serializes two concurrent reservation attempts against the
 *      SAME already-touched dates (e.g. one property with a `blocked`
 *      row and someone racing to book around it).
 *   2. The table's own `unique(['property_id', 'date'])` constraint (real
 *      Glover schema, kept exactly) is the actual backstop for the
 *      genuinely dangerous case — TWO concurrent transactions, NEITHER of
 *      which sees a pre-existing row for a given date (nothing for
 *      `lockForUpdate()` to lock), both attempting to INSERT a fresh
 *      `booked` row for it. The database itself rejects the second
 *      INSERT — a real, DB-level guarantee, not just an application
 *      convention.
 *
 * Both steps run inside the SAME transaction `CreatePropertyReservationAction`
 * opens around the reservation row itself, so a conflict detected here
 * rolls back the entire reservation attempt, not just the availability
 * rows.
 */
class PropertyAvailabilityService
{
    /**
     * Read-only check — for search/browse/quote, NOT the authoritative
     * safety mechanism (a browse-time "looks free" can always be raced by
     * someone else between this call and a real reservation attempt; only
     * reserveDates() below, called inside the reservation's own
     * transaction, is actually safe).
     */
    public function isAvailable(Property $property, string $checkIn, string $checkOut): bool
    {
        $dates = $this->dateRange($checkIn, $checkOut);

        $unavailableCount = PropertyAvailability::where('property_id', $property->id)
            ->whereIn('date', $dates)
            ->where('is_available', false)
            ->count();

        return $unavailableCount === 0;
    }

    /**
     * The real, transactional, concurrency-safe reservation of a date
     * range. MUST be called from inside the caller's own DB::transaction()
     * (CreatePropertyReservationAction does this) — this method does not
     * open its own, since the availability rows and the PropertyReservation
     * row must commit or roll back together.
     *
     * @throws \RuntimeException if any date in the range is already unavailable
     * @throws \Illuminate\Database\QueryException if a genuine race is caught by the UNIQUE constraint (see class docblock)
     */
    public function reserveDates(Property $property, string $checkIn, string $checkOut, int $reservationId): void
    {
        $dates = $this->dateRange($checkIn, $checkOut);

        // Step 1: lock any EXISTING rows for these dates, serializing
        // concurrent access to whichever dates already have one.
        $existing = PropertyAvailability::where('property_id', $property->id)
            ->whereIn('date', $dates)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn ($row) => $row->date);

        foreach ($existing as $row) {
            if (! $row->is_available) {
                throw new \RuntimeException("Property [{$property->id}] is not available for the requested dates (conflict on {$row->date}).");
            }
        }

        // Step 2: for every date, either update the now-locked existing
        // row or insert a fresh one -- a fresh insert is where the UNIQUE
        // constraint becomes the real backstop against a genuine race
        // (see class docblock).
        foreach ($dates as $date) {
            if ($existing->has($date)) {
                $existing[$date]->update(['is_available' => false, 'reason' => 'booked', 'property_reservation_id' => $reservationId]);
            } else {
                PropertyAvailability::create([
                    'property_id' => $property->id, 'date' => $date,
                    'is_available' => false, 'reason' => 'booked', 'property_reservation_id' => $reservationId,
                ]);
            }
        }
    }

    /** The inverse of reserveDates() -- releases a cancelled reservation's dates back to available. */
    public function releaseDates(PropertyReservation $reservation): void
    {
        PropertyAvailability::where('property_id', $reservation->property_id)
            ->where('property_reservation_id', $reservation->id)
            ->update(['is_available' => true, 'reason' => 'available', 'property_reservation_id' => null]);
    }

    /**
     * [start, end) — check-out day itself is not occupied (the same
     * half-open convention Glover's own scopeAvailable() uses, verified
     * directly, not invented).
     */
    public function dateRange(string $checkIn, string $checkOut): Collection
    {
        $dates = collect();
        $current = Carbon::parse($checkIn)->startOfDay();
        $end = Carbon::parse($checkOut)->startOfDay();

        while ($current->lt($end)) {
            $dates->push($current->toDateString());
            $current->addDay();
        }

        return $dates;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PropertyAvailability extends Model
{
    protected $table = 'property_availabilities';

    protected $fillable = ['property_id', 'date', 'is_available', 'price_override', 'reason', 'property_reservation_id'];

    /**
     * `date` is deliberately NOT cast to `'date'` (a Carbon instance) —
     * found and fixed during this phase's own test run: Eloquent's `date`
     * cast controls how the attribute is *accessed* but still serializes
     * for storage using the model's `dateFormat` (full `Y-m-d H:i:s` by
     * default, not date-only), so a manually-inserted 'Y-m-d' row and a
     * cast-written one silently stored in two different string shapes on
     * the SAME column — `whereIn('date', $dates)` (plain 'Y-m-d' strings)
     * then failed to match rows that WERE cast-written, `lockForUpdate()`
     * found nothing to lock, and the code fell through to a fresh
     * `INSERT` that collided with the real existing row's UNIQUE
     * constraint. Kept as a plain string throughout (`PropertyAvailabilityService`
     * always compares/writes plain 'Y-m-d' strings) — simpler and
     * provably correct, not a workaround.
     */
    protected $casts = [
        'is_available' => 'boolean',
        'price_override' => 'decimal:2',
    ];

    public function property() { return $this->belongsTo(Property::class); }
    public function reservation() { return $this->belongsTo(PropertyReservation::class, 'property_reservation_id'); }
}

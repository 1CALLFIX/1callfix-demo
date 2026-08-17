<?php

namespace App\Models;

use App\Contracts\Orderable;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 22.7 (Property Rental) — the fourth `Orderable` implementer.
 * `moduleCode()` returns `rental` (`App\Support\Modules::ALL`).
 *
 * **2026-08-17 slug rename:** this was `car_rental` from Phase 22.7 through
 * Phase 25 — originally chosen because `PHASE_22_PLATFORM_CAPABILITY_
 * RECOVERY_AUDIT.md §5` found no evidence to split the broader Rental
 * family into per-sub-type slugs, so Property Rental used the one slug
 * that existed. That slug's own display label read "Car Rental" the whole
 * time, a real, confirmed collision risk once a genuine Car Rental
 * (rentable-vehicle inventory) vertical is ever built — renamed to its own
 * dedicated `property_rental` slug to remove that risk.
 *
 * **RENTAL MODULE IMPLEMENTATION:** renamed again to `rental` — the real
 * Rental vertical build now exists (Vehicle/Equipment, this phase's own
 * `RentalReservation`), and the product decision is ONE top-level `rental`
 * module for all three `rental_type` values, not a separate module per
 * type. This class (the Property engine) is completely UNCHANGED
 * otherwise — same table, same columns, same lifecycle, same Actions —
 * only the `moduleCode()` return value and the module-activation gate it
 * feeds now read `rental` instead of `property_rental`. Every franchise
 * that already had Property Rental activated keeps working unchanged
 * (`module_activations` rows key on the module's integer id, never the
 * code string — see the rename migration's own docblock).
 */
class PropertyReservation extends Model implements Orderable
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'property_reservations';

    protected $fillable = [
        'code', 'franchise_id', 'zone_id', 'customer_id', 'property_id',
        'check_in_date', 'check_out_date', 'number_of_nights', 'number_of_guests',
        'status', 'price_quoted', 'price_final', 'payment_status', 'payment_method',
        'cancellation_reason_id', 'cancellation_note', 'cancellation_fee',
        'special_requests', 'confirmed_at', 'checked_in_at', 'completed_at',
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'confirmed_at' => 'datetime',
        'checked_in_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function property() { return $this->belongsTo(Property::class); }
    public function statusHistory() { return $this->hasMany(PropertyReservationStatusHistory::class); }
    public function cancellationReason() { return $this->belongsTo(CancellationReason::class); }
    public function commission() { return $this->hasOne(Commission::class); }
    public function payments() { return $this->hasMany(Payment::class); }

    // ============================== Orderable ==============================

    public function moduleCode(): string { return Modules::RENTAL; }
    public function orderCode(): string { return $this->code; }
    public function orderFranchiseId(): int { return $this->franchise_id; }
    public function orderZoneId(): ?int { return $this->zone_id; }
    public function orderCustomerId(): int { return $this->customer_id; }
    public function orderTotalPrice(): float { return (float) ($this->price_final ?? $this->price_quoted); }
    public function orderStatus(): string { return $this->status; }
}

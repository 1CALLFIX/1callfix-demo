<?php

namespace App\Models;

use App\Contracts\Orderable;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * RENTAL MODULE IMPLEMENTATION -- the sixth `Orderable` implementer, and
 * the shared Rental Engine order for the two new rental types (Vehicle,
 * Equipment). `moduleCode()` returns `rental` (`App\Support\Modules::ALL`)
 * regardless of `rental_type` -- one top-level module, `rental_type` is a
 * sub-dimension inside it, not a second module-activation axis (see the
 * `rental_reservations` migration's own docblock for why this differs
 * from `MarketplaceOrder::moduleCode()`, which DOES read a live column:
 * Marketplace's `module` column selects between four independently-
 * activatable verticals; Rental's three types are explicitly ONE
 * activatable vertical per this phase's own product decision).
 *
 * Property Rental is NOT represented by this model -- it keeps its own
 * `PropertyReservation`/`property_reservations` engine untouched. This
 * model only ever holds `rental_type = vehicle|equipment` rows.
 */
class RentalReservation extends Model implements Orderable
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'rental_reservations';

    protected $fillable = [
        'code', 'franchise_id', 'zone_id', 'customer_id',
        'rental_type', 'rentable_type', 'rentable_id',
        'rental_mode', 'driver_field_worker_id',
        'starts_at', 'ends_at', 'duration_unit', 'duration_quantity',
        'status', 'price_quoted', 'price_final', 'payment_status', 'payment_method',
        'deposit_amount', 'deposit_status', 'deposit_note',
        'cancellation_reason_id', 'cancellation_note', 'cancellation_fee',
        'special_requests', 'confirmed_at', 'picked_up_at', 'returned_at',
        'inspected_at', 'inspection_notes', 'completed_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'returned_at' => 'datetime',
        'inspected_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function rentable() { return $this->morphTo(); }
    public function driver() { return $this->belongsTo(FieldWorker::class, 'driver_field_worker_id'); }
    public function statusHistory() { return $this->hasMany(RentalReservationStatusHistory::class); }
    public function cancellationReason() { return $this->belongsTo(CancellationReason::class); }
    public function commission() { return $this->hasOne(Commission::class); }
    public function payments() { return $this->hasMany(Payment::class); }

    /** The Provider who owns the rented item -- Vehicle/EquipmentItem both expose provider(), the real "earner" for CommissionService. */
    public function provider(): ?Provider
    {
        return $this->rentable?->provider;
    }

    /**
     * Shared by CreateRentalReservationAction and ExtendRentalReservationAction
     * (extracted once a real second caller existed, this codebase's own
     * "generalize only once real repetition exists" convention). ONE
     * duration unit per item drives the quote -- see
     * CreateRentalReservationAction's own docblock for why.
     */
    public static function computeDurationQuantity(Carbon $startsAt, Carbon $endsAt, string $unit): float
    {
        // round(..., 2) on every arm, not just weekly -- Carbon's diff*()
        // methods return a precise float (real elapsed time), so two
        // `now()`-derived instants a few microseconds apart (e.g. two
        // separate now()->addDays(...) calls) produce values like
        // 2.0000000008796297, not a clean 2.0. Rounding to 2 decimal places
        // normalizes that jitter without hiding a genuine fractional
        // duration (e.g. a real 2.5-day rental still rounds to 2.5).
        return match ($unit) {
            'hourly' => round($startsAt->diffInMinutes($endsAt) / 60, 2),
            'daily' => round($startsAt->diffInDays($endsAt), 2),
            'weekly' => round($startsAt->diffInDays($endsAt) / 7, 2),
            'monthly' => round($startsAt->diffInMonths($endsAt), 2),
        };
    }

    // ============================== Orderable ==============================

    public function moduleCode(): string { return Modules::RENTAL; }
    public function orderCode(): string { return $this->code; }
    public function orderFranchiseId(): int { return $this->franchise_id; }
    public function orderZoneId(): ?int { return $this->zone_id; }
    public function orderCustomerId(): int { return $this->customer_id; }
    public function orderTotalPrice(): float { return (float) ($this->price_final ?? $this->price_quoted); }
    public function orderStatus(): string { return $this->status; }
}

<?php

namespace App\Models;

use App\Contracts\Orderable;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 22.4 (Parcel) — the second real Orderable implementer, per Phase
 * 22.2's Option A decision. A dedicated table, not a row in `bookings`;
 * see PHASE_22_2_ORDER_ENGINE_ARCHITECTURE_DECISION.md and
 * PHASE_22_4_PARCEL_DESIGN.md for the full reasoning.
 */
class ParcelOrder extends Model implements Orderable
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'parcel_orders';

    protected $fillable = [
        'code',
        'franchise_id',
        'zone_id',
        'customer_id',
        'pickup_address_id',
        'dropoff_address_id',
        'assigned_worker_id',
        'package_description',
        'package_weight_kg',
        'package_size',
        'status',
        'price_quoted',
        'price_final',
        'payment_status',
        'payment_method',
        'pickup_otp',
        'delivery_otp',
        'cancellation_reason_id',
        'cancellation_note',
        'cancellation_fee',
        'customer_note',
        'picked_up_at',
        'delivered_at',
    ];

    protected $casts = [
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function pickupAddress() { return $this->belongsTo(Address::class, 'pickup_address_id'); }
    public function dropoffAddress() { return $this->belongsTo(Address::class, 'dropoff_address_id'); }
    public function assignedWorker() { return $this->belongsTo(FieldWorker::class, 'assigned_worker_id'); }
    public function statusHistory() { return $this->hasMany(ParcelOrderStatusHistory::class); }
    public function cancellationReason() { return $this->belongsTo(CancellationReason::class); }
    public function commission() { return $this->hasOne(Commission::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    /** Reverse side of DispatchAttempt::dispatchable() -- Operations/Health dispatch monitoring (mission Phase 7). */
    public function dispatchAttempts() { return $this->morphMany(DispatchAttempt::class, 'dispatchable'); }

    // ============================== Orderable ==============================

    public function moduleCode(): string { return Modules::PARCEL; }
    public function orderCode(): string { return $this->code; }
    public function orderFranchiseId(): int { return $this->franchise_id; }
    public function orderZoneId(): ?int { return $this->zone_id; }
    public function orderCustomerId(): int { return $this->customer_id; }
    public function orderTotalPrice(): float { return (float) ($this->price_final ?? $this->price_quoted); }
    public function orderStatus(): string { return $this->status; }
}

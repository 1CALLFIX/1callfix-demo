<?php

namespace App\Models;

use App\Contracts\Orderable;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 22.6 (Taxi) — the third Orderable implementer, reusing the pattern
 * Parcel (Phase 22.4) proved out: its own dedicated order table, a
 * FieldWorker holding a pre-existing capability (`taxi_driver`), dispatch
 * via the already-polymorphic `dispatch_attempts`, Setting-driven
 * zero-default rates. See `PHASE_22_5_REMAINING_MODULES_TRIAGE.md` for why
 * Taxi (unlike Ecommerce/Food/Grocery/Pharmacy) doesn't require a new
 * foundational business-entity decision first.
 */
class TaxiRide extends Model implements Orderable
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'taxi_rides';

    protected $fillable = [
        'code',
        'franchise_id',
        'zone_id',
        'customer_id',
        'pickup_address_id',
        'dropoff_address_id',
        'assigned_worker_id',
        'status',
        'distance_km',
        'price_quoted',
        'price_final',
        'payment_status',
        'payment_method',
        'start_otp',
        'cancellation_reason_id',
        'cancellation_note',
        'cancellation_fee',
        'customer_note',
        'trip_started_at',
        'trip_completed_at',
    ];

    protected $casts = [
        'trip_started_at' => 'datetime',
        'trip_completed_at' => 'datetime',
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function pickupAddress() { return $this->belongsTo(Address::class, 'pickup_address_id'); }
    public function dropoffAddress() { return $this->belongsTo(Address::class, 'dropoff_address_id'); }
    public function assignedWorker() { return $this->belongsTo(FieldWorker::class, 'assigned_worker_id'); }
    public function statusHistory() { return $this->hasMany(TaxiRideStatusHistory::class); }
    public function cancellationReason() { return $this->belongsTo(CancellationReason::class); }
    public function commission() { return $this->hasOne(Commission::class); }
    public function payments() { return $this->hasMany(Payment::class); }

    // ============================== Orderable ==============================

    public function moduleCode(): string { return Modules::TAXI; }
    public function orderCode(): string { return $this->code; }
    public function orderFranchiseId(): int { return $this->franchise_id; }
    public function orderZoneId(): ?int { return $this->zone_id; }
    public function orderCustomerId(): int { return $this->customer_id; }
    public function orderTotalPrice(): float { return (float) ($this->price_final ?? $this->price_quoted); }
    public function orderStatus(): string { return $this->status; }
}

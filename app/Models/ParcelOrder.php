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
        'pickup_contact_name',
        'pickup_contact_phone',
        'recipient_name',
        'recipient_phone',
        'recipient_refused_at',
        'assigned_worker_id',
        'package_description',
        'package_weight_kg',
        'package_size',
        'distance_km',
        'status',
        'price_quoted',
        'price_final',
        'price_breakdown',
        'payment_status',
        'payment_method',
        'cash_collect_at',
        'pickup_otp',
        'delivery_otp',
        'cancellation_reason_id',
        'cancellation_note',
        'cancellation_fee',
        'customer_note',
        'picked_up_at',
        'cash_collected_at',
        'delivered_at',
    ];

    protected $casts = [
        'picked_up_at' => 'datetime',
        'cash_collected_at' => 'datetime',
        'recipient_refused_at' => 'datetime',
        'delivered_at' => 'datetime',
        'price_breakdown' => 'array',
    ];

    /** Rider has committed and the parcel is not yet handed over or closed. */
    public const ACTIVE_RIDER_STATUSES = ['assigned', 'worker_en_route_pickup', 'picked_up', 'en_route_dropoff'];

    /** The customer may still cancel: the parcel has not left the sender. */
    public const CUSTOMER_CANCELLABLE_STATUSES = ['pending', 'searching_worker', 'assigned', 'worker_en_route_pickup'];

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
    public function cashDue() { return $this->hasOne(FieldWorkerCashDue::class); }

    /** An online order waits here, undispatched, until its Razorpay payment is captured. */
    public function isAwaitingOnlinePayment(): bool
    {
        return $this->payment_method === 'online' && $this->payment_status === 'pending' && $this->status === 'pending';
    }

    /** Pay at: Drop (owner decision 2026-10-24): the recipient pays the rider cash at the door, not the sender at pickup. */
    public function collectsCashAtDelivery(): bool
    {
        return $this->payment_method === 'cash' && $this->cash_collect_at === 'drop';
    }

    public function canBeCancelledByCustomer(): bool
    {
        return in_array($this->status, self::CUSTOMER_CANCELLABLE_STATUSES, true);
    }
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

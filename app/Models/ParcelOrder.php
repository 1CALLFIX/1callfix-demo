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
        'refused_at',
        'refusal_reason',
        'refusal_note',
        'refused_lat',
        'refused_lng',
        'refusal_rider_pay',
        'safety_flagged_at',
        'assigned_worker_id',
        'package_description',
        'package_weight_kg',
        'package_size',
        'package_contents',
        'restricted_items_confirmed_at',
        'delivery_type',
        'scheduled_at',
        'dispatch_released_at',
        'vehicle_type',
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
        'arrived_at_pickup_at',
        'waiting_minutes',
        'waiting_charge',
        'cash_collected_at',
        'delivered_at',
        // Stage 2 (2026-10-31): return trip + multi-drop progress
        'return_trip',
        'return_otp',
        'drop_delivered_at',
        'drops_completed_at',
        'returned_at',
        // Stage 2: protection
        'declared_value',
        'protection_fee',
        // Stage 2: prepaid parcel pack
        'parcel_pack_purchase_id',
        'pack_covered',
        'pack_credit_returned_at',
        // Stage 2: cash on delivery for shops
        'cod_amount',
        'cod_collected_at',
    ];

    protected $casts = [
        'picked_up_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'dispatch_released_at' => 'datetime',
        'restricted_items_confirmed_at' => 'datetime',
        'arrived_at_pickup_at' => 'datetime',
        'waiting_charge' => 'decimal:2',
        'cash_collected_at' => 'datetime',
        'recipient_refused_at' => 'datetime',
        'refused_at' => 'datetime',
        'refusal_rider_pay' => 'decimal:2',
        'safety_flagged_at' => 'datetime',
        'delivered_at' => 'datetime',
        'price_breakdown' => 'array',
        'return_trip' => 'boolean',
        'drop_delivered_at' => 'datetime',
        'drops_completed_at' => 'datetime',
        'returned_at' => 'datetime',
        'declared_value' => 'decimal:2',
        'protection_fee' => 'decimal:2',
        'pack_covered' => 'decimal:2',
        'pack_credit_returned_at' => 'datetime',
        'cod_amount' => 'decimal:2',
        'cod_collected_at' => 'datetime',
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
    public function safetyPhotos() { return $this->hasMany(ParcelSafetyPhoto::class)->orderBy('taken_at'); }
    /** Stage 2: drop 2, 3, ... (drop 1 is this order's own dropoff). */
    public function stops() { return $this->hasMany(ParcelOrderStop::class)->orderBy('sequence'); }

    public function rating() { return $this->hasOne(ParcelRating::class); }

    public function messages() { return $this->hasMany(ParcelOrderMessage::class); }

    public function protectionClaims() { return $this->hasMany(ParcelProtectionClaim::class); }
    public function packPurchase() { return $this->belongsTo(ParcelPackPurchase::class, 'parcel_pack_purchase_id'); }

    /** Stage 2: what the customer still pays in money — the price less what a parcel pack credit covered. */
    public function amountDue(): float
    {
        return round(max(0.0, (float) $this->price_quoted - (float) $this->pack_covered), 2);
    }

    /** Stage 2: the sender paid for protection (claims up to declared_value). */
    public function isProtected(): bool
    {
        return $this->declared_value !== null && (float) $this->declared_value > 0;
    }

    /** Stage 2: the rider collects the item price from the recipient for the sender (cash on delivery). */
    public function isCod(): bool
    {
        return $this->cod_amount !== null && (float) $this->cod_amount > 0;
    }

    /** Stage 2: more than one drop point. */
    public function isMultiDrop(): bool
    {
        return $this->stops->isNotEmpty();
    }

    /** Stage 2: every drop is done and the rider is bringing something back to the sender. */
    public function isReturning(): bool
    {
        return $this->return_trip && $this->drops_completed_at !== null && $this->returned_at === null
            && $this->status === 'en_route_dropoff';
    }

    /** Stage 2: drop points not yet handed over: 'main' for drop 1, else the stop id. @return list<int|string> */
    public function pendingDropKeys(): array
    {
        $keys = $this->drop_delivered_at ? [] : ['main'];
        foreach ($this->stops as $stop) {
            if (! $stop->delivered_at) {
                $keys[] = $stop->id;
            }
        }

        return $keys;
    }

    /** An online order waits here, undispatched, until its Razorpay payment is captured. */
    public function isAwaitingOnlinePayment(): bool
    {
        return $this->payment_method === 'online' && $this->payment_status === 'pending' && $this->status === 'pending';
    }

    /** NO PAYMENT, NO BOOKING (owner 2026-11-07): real orders only — not an online order whose payment never arrived. */
    public function scopeBooked($query)
    {
        return $query->where(fn ($q) => $q->where('payment_method', '!=', 'online')
            ->orWhereNull('payment_method')
            ->orWhere('payment_status', '!=', 'pending')
            ->orWhereNotIn('status', ['pending', 'cancelled']));
    }

    /** Unpaid online orders (open or ended): payment attempts, listed only for support. */
    public function scopePaymentAttempts($query)
    {
        return $query->where('payment_method', 'online')->where('payment_status', 'pending')->whereIn('status', ['pending', 'cancelled']);
    }

    /** Pay at: Drop (owner decision 2026-10-24): the recipient pays the rider cash at the door, not the sender at pickup. */
    public function collectsCashAtDelivery(): bool
    {
        return $this->payment_method === 'cash' && $this->cash_collect_at === 'drop';
    }

    /** Stage 1: the customer picked a pickup time instead of "Now". */
    public function isScheduled(): bool
    {
        return $this->delivery_type === 'scheduled' && $this->scheduled_at !== null;
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

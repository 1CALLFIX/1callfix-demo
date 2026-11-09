<?php

namespace App\Models;

use App\Contracts\Orderable;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 24 (Marketplace Foundation) — the fifth `Orderable` implementer,
 * and the first shared across multiple verticals (Ecommerce/Food/Grocery/
 * Pharmacy, distinguished by `module`). See
 * PHASE_24_MARKETPLACE_FOUNDATION_ARCHITECTURE.md §4 for why this
 * deliberately diverges from Phase 22.2's per-vertical-table precedent.
 * `moduleCode()` returns the ORDER's own `module` column (not a fixed
 * constant like every prior implementer) — the one real place this
 * implementer's `Orderable` behavior genuinely differs from its four
 * predecessors, because it genuinely serves four verticals, not one.
 */
class MarketplaceOrder extends Model implements Orderable
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'marketplace_orders';

    protected $fillable = [
        'code', 'franchise_id', 'zone_id', 'customer_id', 'store_id', 'module',
        'order_type', 'delivery_address_id', 'recipient_name', 'recipient_phone', 'assigned_worker_id', 'delivery_otp',
        'subtotal', 'delivery_fee', 'packaging_charge', 'distance_km', 'tax_amount', 'tax_by_platform', 'discount_amount', 'total_amount',
        'status', 'price_final', 'payment_status', 'payment_method',
        'cancellation_reason_id', 'cancellation_note', 'cancellation_fee',
        'coupon_code', 'coupon_discount_amount', 'special_instructions',
        'confirmed_at', 'ready_at', 'completed_at',
        // Food F2
        'accept_by', 'ready_by', 'rider_called_at', 'rider_at_store_at', 'pickup_code', 'picked_up_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'ready_at' => 'datetime',
        'completed_at' => 'datetime',
        'accept_by' => 'datetime',
        'ready_by' => 'datetime',
        'rider_called_at' => 'datetime',
        'rider_at_store_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'tax_by_platform' => 'boolean',
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function store() { return $this->belongsTo(Store::class); }
    public function deliveryAddress() { return $this->belongsTo(Address::class, 'delivery_address_id'); }
    public function assignedWorker() { return $this->belongsTo(FieldWorker::class, 'assigned_worker_id'); }
    public function items() { return $this->hasMany(MarketplaceOrderItem::class); }
    public function statusHistory() { return $this->hasMany(MarketplaceOrderStatusHistory::class); }
    public function cancellationReason() { return $this->belongsTo(CancellationReason::class); }
    public function commission() { return $this->hasOne(Commission::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    /** Reverse side of DispatchAttempt::dispatchable() -- Operations/Health dispatch monitoring (mission Phase 7). */
    public function dispatchAttempts() { return $this->morphMany(DispatchAttempt::class, 'dispatchable'); }
    public function settlement() { return $this->hasOne(MarketplaceSettlement::class); }

    /** E-commerce Phase 0: an online order is held from the seller until the Razorpay capture webhook marks it paid. */
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

    /** THUMB RULE — any discount or coupon on the order is a benefit, so the order must be paid online. */
    public function usesBenefit(): bool
    {
        return (float) $this->discount_amount > 0 || (float) $this->coupon_discount_amount > 0 || ! empty($this->coupon_code);
    }

    public function isFood(): bool
    {
        return $this->module === Modules::FOOD;
    }

    /**
     * Food F2: a delivery order a rider can hold — the food is ready, or (food only) still cooking but a rider was
     * already called for it (food.rider_call_before_ready_minutes). The rider can only deliver once it is ready.
     */
    public function scopeRiderActive($query)
    {
        return $query->where('order_type', 'delivery')->where(function ($q) {
            $q->where('status', 'ready')
                ->orWhere(fn ($q) => $q->where('status', 'preparing')->whereNotNull('rider_called_at'));
        });
    }

    /** Food F2: the promised ready time in the order's own city time, e.g. "7:45 PM". */
    public function readyByLocal(): ?string
    {
        if ($this->ready_by === null) {
            return null;
        }
        $this->loadMissing('franchise');

        return $this->ready_by->copy()->setTimezone(app(\App\Services\TimezoneResolver::class)->timezoneFor($this->franchise))->format('g:i A');
    }

    /** Food F2: can a rider be offered / take this order now? */
    protected static function booted(): void
    {
        // Owner 2026-11-09: every delivery order gets its pickup code when it is placed.
        static::creating(function (MarketplaceOrder $order) {
            if ($order->order_type === 'delivery' && $order->pickup_code === null) {
                $order->pickup_code = self::freshPickupCode($order->store_id);
            }
        });
    }

    /** 4 digits, not used by another open order of the same restaurant / shop. */
    public static function freshPickupCode(?int $storeId): string
    {
        $taken = self::where('store_id', $storeId)->whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('pickup_code')->pluck('pickup_code')->all();
        do {
            $code = (string) random_int(1000, 9999);
        } while (in_array($code, $taken, true));

        return $code;
    }

    /** The pickup code, made now for an order placed before codes existed. */
    public function ensurePickupCode(): ?string
    {
        if ($this->order_type !== 'delivery') {
            return null;
        }
        if ($this->pickup_code === null) {
            $this->forceFill(['pickup_code' => self::freshPickupCode($this->store_id)])->save();
        }

        return $this->pickup_code;
    }

    /** Owner 2026-11-09: how far the customer is from the restaurant / shop, shown to the rider before pickup. */
    public function dropDistanceKm(): ?float
    {
        $from = $this->store;
        $to = $this->deliveryAddress;
        if (! $from || ! $to || $from->lat === null || $to->lat === null) {
            return null;
        }

        return round(app(\App\Services\DispatchService::class)->haversineKm((float) $from->lat, (float) $from->lng, (float) $to->lat, (float) $to->lng), 1);
    }

    /**
     * Owner 2026-11-09: "Pickup 5 km · 15 min" and "Deliver 3 km · 8 min" on the rider's card. $to = 'pickup' (rider →
     * restaurant / shop) or 'drop' (rider → customer once picked up; restaurant → customer before). Straight-line km,
     * the same measure as "Drop: 3.2 km away"; minutes allow for roads (x1.3) at the Super Admin's rider speed.
     * Null when a location is missing or the rider's phone has not reported for 15 minutes.
     *
     * @return array{km: float, minutes: int}|null
     */
    public function riderLeg(string $to): ?array
    {
        $rider = $this->assignedWorker;
        $riderFresh = $rider && $rider->current_lat !== null && $rider->current_lng !== null
            && $rider->location_updated_at && $rider->location_updated_at->gt(now()->subMinutes(15));
        $store = $this->store;

        if ($to === 'pickup') {
            $from = $riderFresh ? [(float) $rider->current_lat, (float) $rider->current_lng] : null;
            $dest = $store && $store->lat !== null ? [(float) $store->lat, (float) $store->lng] : null;
        } else {
            $from = $this->picked_up_at
                ? ($riderFresh ? [(float) $rider->current_lat, (float) $rider->current_lng] : null)
                : ($store && $store->lat !== null ? [(float) $store->lat, (float) $store->lng] : null);
            $address = $this->deliveryAddress;
            $dest = $address && $address->lat !== null ? [(float) $address->lat, (float) $address->lng] : null;
        }
        if (! $from || ! $dest) {
            return null;
        }

        $km = app(\App\Services\DispatchService::class)->haversineKm($from[0], $from[1], $dest[0], $dest[1]);
        $speed = max(5, (int) \App\Support\MarketplaceSettings::get('marketplace.dispatch.rider_speed_kmh'));

        return ['km' => round($km, 1), 'minutes' => max(1, (int) ceil($km * 1.3 / $speed * 60))];
    }

    public function isOpenForRider(): bool
    {
        return $this->order_type === 'delivery'
            && ($this->status === 'ready' || ($this->status === 'preparing' && $this->rider_called_at !== null));
    }

    // ============================== Orderable ==============================

    public function moduleCode(): string { return $this->module; }
    public function orderCode(): string { return $this->code; }
    public function orderFranchiseId(): int { return $this->franchise_id; }
    public function orderZoneId(): ?int { return $this->zone_id; }
    public function orderCustomerId(): int { return $this->customer_id; }
    public function orderTotalPrice(): float { return (float) ($this->price_final ?? $this->total_amount); }
    public function orderStatus(): string { return $this->status; }
}

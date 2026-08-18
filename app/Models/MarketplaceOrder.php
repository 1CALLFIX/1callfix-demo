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
        'order_type', 'delivery_address_id', 'assigned_worker_id', 'delivery_otp',
        'subtotal', 'delivery_fee', 'tax_amount', 'discount_amount', 'total_amount',
        'status', 'price_final', 'payment_status', 'payment_method',
        'cancellation_reason_id', 'cancellation_note', 'cancellation_fee',
        'coupon_code', 'coupon_discount_amount', 'special_instructions',
        'confirmed_at', 'ready_at', 'completed_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'ready_at' => 'datetime',
        'completed_at' => 'datetime',
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

    // ============================== Orderable ==============================

    public function moduleCode(): string { return $this->module; }
    public function orderCode(): string { return $this->code; }
    public function orderFranchiseId(): int { return $this->franchise_id; }
    public function orderZoneId(): ?int { return $this->zone_id; }
    public function orderCustomerId(): int { return $this->customer_id; }
    public function orderTotalPrice(): float { return (float) ($this->price_final ?? $this->total_amount); }
    public function orderStatus(): string { return $this->status; }
}

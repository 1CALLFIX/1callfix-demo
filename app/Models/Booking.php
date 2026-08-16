<?php

namespace App\Models;

use App\Contracts\Orderable;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 22.2: implements Orderable — see PHASE_22_2_ORDER_ENGINE_
 * ARCHITECTURE_DECISION.md. Every method below is a one-line delegation to
 * a column this class already had; this is a zero-behavior-change addition,
 * not a refactor.
 */
class Booking extends Model implements Orderable
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'bookings';

    protected $fillable = [
        'code',
        'franchise_id',
        'zone_id',
        'customer_id',
        'provider_id',
        'assigned_worker_id',
        'service_id',
        'address_id',
        'status',
        'scheduled_at',
        'price_quoted',
        'price_final',
        'payment_status',
        'payment_method',
        'coupon_id',
        'cancellation_reason_id',
        'cancellation_note',
        'cancellation_fee',
        'customer_note',
        'start_otp',
        'completion_otp',
        'completed_at',
        'hold_category',
        'hold_reason',
        'hold_note',
        'on_hold_since'
    ];

    protected $casts = ['scheduled_at' => 'datetime', 'completed_at' => 'datetime', 'on_hold_since' => 'datetime'];
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function provider() { return $this->belongsTo(Provider::class); }
    /** Phase B0.2 — who is physically executing this booking, if delegated. provider_id (accountable Partner) is unaffected either way. */
    public function assignedWorker() { return $this->belongsTo(FieldWorker::class, 'assigned_worker_id'); }
    public function service() { return $this->belongsTo(Service::class); }
    public function address() { return $this->belongsTo(Address::class); }
    public function options() { return $this->hasMany(BookingOption::class); }
    public function extraItems() { return $this->hasMany(BookingExtraItem::class); }
    public function statusHistory() { return $this->hasMany(BookingStatusHistory::class); }
    public function dispatchAttempts() { return $this->hasMany(DispatchAttempt::class); }
    /** Phase 21 item TECH-4 -- ChatMessage::booking() already existed since Phase 6; this is just the missing inverse side, not a schema change. */
    public function chatMessages() { return $this->hasMany(ChatMessage::class); }
    public function payment() { return $this->hasOne(Payment::class); }
    public function commission() { return $this->hasOne(Commission::class); }
    public function compensations() { return $this->hasMany(BookingCompensation::class); }
    public function review() { return $this->hasOne(Review::class); }
    public function cancellationReason() { return $this->belongsTo(CancellationReason::class); }

    // ============================== Orderable ==============================

    public function moduleCode(): string { return Modules::SERVICE; }
    public function orderCode(): string { return $this->code; }
    public function orderFranchiseId(): int { return $this->franchise_id; }
    public function orderZoneId(): ?int { return $this->zone_id; }
    public function orderCustomerId(): int { return $this->customer_id; }
    public function orderTotalPrice(): float { return (float) ($this->price_final ?? $this->price_quoted); }
    public function orderStatus(): string { return $this->status; }
}

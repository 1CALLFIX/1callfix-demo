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

    protected static function booted(): void
    {
        // Step 5: freeze the cancellation policy in force at booking time; every later fee reads this.
        static::creating(function (Booking $booking) {
            if ($booking->cancellation_policy_snapshot === null) {
                $booking->cancellation_policy_snapshot = \App\Services\Cancellation\PolicySettings::snapshot($booking);
            }
        });
    }

    public function quotes() { return $this->hasMany(BookingQuote::class); }
    public function callAttempts() { return $this->hasMany(BookingCallAttempt::class); }

    protected $fillable = [
        'code',
        'booking_bundle_id',
        'franchise_id',
        'zone_id',
        'customer_id',
        'provider_id',
        'assigned_worker_id',
        'service_id',
        'address_id',
        'status',
        'dispatch_deadline_at',
        'dispatch_escalated_at',
        'scheduled_offers_sent_at',
        'scheduled_last_offer_at',
        'scheduled_early_warning_at',
        'scheduled_urgent_alert_at',
        'scheduled_reminder_1_at',
        'scheduled_reminder_2_at',
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
        'start_otp_expires_at',
        'start_otp_attempts',
        'start_otp_verified_at',
        'completion_otp',
        'completion_otp_expires_at',
        'completion_otp_attempts',
        'completion_otp_verified_at',
        'completed_at',
        'hold_category',
        'hold_reason',
        'hold_note',
        'on_hold_since',
        // REF 1CF-CANCEL-POLICY-001
        'cancelled_by_role',
        'spares_sourced_by',
        'spares_expected_at',
        'interim_progress_percent',
        'interim_parts_cost',
        'interim_evidence',
        'interim_declared_at',
        'interim_dispute_status',
        'interim_disputed_at',
        'interim_dispute_note',
        'cancellation_fee_basis',
        'spares_notices',
        'cancellation_policy_snapshot',
        'arrival_lat',
        'arrival_lng',
        'arrival_verified_at',
        'arrival_distance_m',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
        // REF 1CF-IMPLEMENT-20260922-L01
        'dispatch_deadline_at' => 'datetime',
        'dispatch_escalated_at' => 'datetime',
        // REF 1CF-SCHEDULING-DISPATCH-001
        'scheduled_offers_sent_at' => 'datetime',
        'scheduled_last_offer_at' => 'datetime',
        'scheduled_early_warning_at' => 'datetime',
        'scheduled_urgent_alert_at' => 'datetime',
        'scheduled_reminder_1_at' => 'datetime',
        'scheduled_reminder_2_at' => 'datetime',
        'on_hold_since' => 'datetime',
        'spares_expected_at' => 'date',
        'interim_evidence' => 'array',
        'interim_declared_at' => 'datetime',
        'interim_disputed_at' => 'datetime',
        'cancellation_fee_basis' => 'array',
        'spares_notices' => 'array',
        'cancellation_policy_snapshot' => 'array',
        'arrival_verified_at' => 'datetime',
        // Phase E5 — booking OTP hardening metadata (see BookingOtpService).
        'start_otp_expires_at' => 'datetime',
        'start_otp_verified_at' => 'datetime',
        'completion_otp_expires_at' => 'datetime',
        'completion_otp_verified_at' => 'datetime',
    ];
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
    /** Phase E1 — the multi-service wrapper this booking belongs to, if any. NULL for every standalone single-service booking. */
    public function bundle() { return $this->belongsTo(BookingBundle::class, 'booking_bundle_id'); }
    public function commission() { return $this->hasOne(Commission::class); }
    public function compensations() { return $this->hasMany(BookingCompensation::class); }
    public function review() { return $this->hasOne(Review::class); }
    public function cancellationRequests() { return $this->hasMany(BookingCancellationRequest::class); }
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

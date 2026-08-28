<?php

namespace App\Models;

use App\Contracts\Orderable;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase E1 (Multi-Service Booking — Data/Model Foundation; see also
 * PHASE_22_2_ORDER_ENGINE_ARCHITECTURE_DECISION.md for why a generic orders
 * table was rejected). An additive wrapper over one-or-more child `Booking`
 * rows. Existing single-service bookings keep `booking_bundle_id = NULL` and
 * are completely unaffected — this class adds a wrapper, it does not replace
 * `Booking` or any booking engine.
 *
 * E1 is the schema/model layer ONLY: there is no bundle creation flow,
 * pricing, wallet/payment, dispatch, scheduling or lifecycle behaviour here
 * yet (those are E2–E7). `status` is a plain stored latch
 * (active/completed/cancelled); the live cross-child picture is read on
 * demand via derivedStatus() and never written back.
 *
 * Implements Orderable the same zero-behaviour-change way `Booking` and
 * `MarketplaceOrder` do — every method is a one-line delegation to a column
 * this class already has. orderStatus() deliberately returns the stored
 * latch, NOT derivedStatus().
 */
class BookingBundle extends Model implements Orderable
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'booking_bundles';

    protected $fillable = [
        'code',
        'idempotency_key',      // Phase E2 — caller-supplied bundle-create replay key
        'request_fingerprint',  // Phase E2 — sha256 of the normalised create request
        'franchise_id',
        'zone_id',
        'customer_id',
        'address_id',
        'status',
        'payment_status',
        'payment_method',
        'total_price_quoted',
        'total_price_final',
        'cancellation_note',
        'cancellation_fee',
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function address() { return $this->belongsTo(Address::class); }
    /** The child single-service bookings this bundle wraps (0 at creation time in E1). */
    public function children() { return $this->hasMany(Booking::class, 'booking_bundle_id'); }
    /** Bundle-level payment, if one is ever recorded (purpose = 'booking_bundle'; E2+, never written in E1). */
    public function payment() { return $this->hasOne(Payment::class, 'booking_bundle_id'); }

    // ============================== Orderable ==============================

    public function moduleCode(): string { return Modules::SERVICE; }
    public function orderCode(): string { return $this->code; }
    public function orderFranchiseId(): int { return $this->franchise_id; }
    public function orderZoneId(): ?int { return $this->zone_id; }
    public function orderCustomerId(): int { return $this->customer_id; }
    public function orderTotalPrice(): float { return (float) ($this->total_price_final ?? $this->total_price_quoted); }

    /** The stored latch, never the derived view — see derivedStatus() for the cross-child picture. */
    public function orderStatus(): string { return $this->status; }

    /**
     * Read-only, computed on demand from the current child `Booking` states —
     * never stored. The `status` column stays a simple latch; this is the
     * "what is actually happening across the children right now" view that
     * later E-steps build their bundle FSM / dispatch consolidation on.
     *
     * NOT a bundle FSM and NOT wired to any automatic latch transition in
     * E1. Child status vocabulary is `Booking`'s own
     * (pending / searching_provider / assigned / provider_en_route /
     * in_progress / completed / cancelled / disputed).
     *
     *   no children ................................. stored latch (unchanged)
     *   every outstanding child pre-assignment ....... 'pending'
     *   some finished, others still outstanding ...... 'partially_completed'
     *   work under way, nothing finished ............. 'in_progress'
     *   all children completed ....................... 'completed'
     *   all children cancelled ....................... 'cancelled'
     *   completed + cancelled, nothing outstanding ... 'completed'
     */
    public function derivedStatus(): string
    {
        $statuses = $this->children->pluck('status');

        if ($statuses->isEmpty()) {
            return $this->status;
        }

        $terminal = ['completed', 'cancelled'];
        $outstanding = $statuses->reject(fn ($s) => in_array($s, $terminal, true));

        if ($outstanding->isEmpty()) {
            if ($statuses->every(fn ($s) => $s === 'cancelled')) {
                return 'cancelled';
            }

            // all completed, or a completed + cancelled mixture — no work left
            return 'completed';
        }

        if ($outstanding->every(fn ($s) => in_array($s, ['pending', 'searching_provider'], true))) {
            return $statuses->contains(fn ($s) => in_array($s, $terminal, true))
                ? 'partially_completed'
                : 'pending';
        }

        return $statuses->contains(fn ($s) => in_array($s, $terminal, true))
            ? 'partially_completed'
            : 'in_progress';
    }
}

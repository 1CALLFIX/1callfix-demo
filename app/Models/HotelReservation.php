<?php

namespace App\Models;

use App\Contracts\Orderable;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HOTEL / STAY BOOKING MODULE -- the sixth `Orderable` implementer.
 * `moduleCode()` returns `hotel` (`App\Support\Modules::HOTEL`), its OWN
 * top-level module -- never nested under `rental`, see `Modules::HOTEL`'s
 * own docblock.
 */
class HotelReservation extends Model implements Orderable
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'hotel_reservations';

    protected $fillable = [
        'code', 'franchise_id', 'zone_id', 'customer_id', 'accommodation_id',
        'check_in_date', 'check_out_date', 'number_of_nights',
        'number_of_rooms', 'number_of_adults', 'number_of_children',
        'status', 'price_quoted', 'price_final', 'payment_status', 'payment_method',
        'cancellation_reason_id', 'cancellation_note', 'cancellation_fee',
        'special_requests', 'confirmed_at', 'checked_in_at', 'checked_out_at', 'completed_at',
        // Hotels H2b: taxes, fee and what the customer pays; unpaid hold; business guest's GST invoice.
        'tax_amount', 'convenience_fee', 'amount_due', 'payment_expires_at', 'guest_gst_number', 'guest_business_name',
        // Hotels H3: e-voucher code, hotel accept deadline, no-show, who cancelled.
        'check_in_code', 'accept_by', 'no_show', 'cancelled_by',
        // Hotels step 2: a coupon taken off the room price (online payment only).
        'coupon_code', 'coupon_discount_amount',
        // Hotels step 3: the cheaper no-refund price; "Change dates" (this booking replaces an older one).
        'non_refundable', 'replaces_reservation_id',
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'confirmed_at' => 'datetime',
        'payment_expires_at' => 'datetime',
        'accept_by' => 'datetime',
        'no_show' => 'boolean',
        'non_refundable' => 'boolean',
        'checked_in_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }
    public function customer() { return $this->belongsTo(User::class, 'customer_id'); }
    public function accommodation() { return $this->belongsTo(Accommodation::class); }
    public function rooms() { return $this->hasMany(HotelReservationRoom::class); }
    public function guests() { return $this->hasMany(HotelGuest::class); }
    public function statusHistory() { return $this->hasMany(HotelReservationStatusHistory::class); }
    public function cancellationReason() { return $this->belongsTo(CancellationReason::class); }
    public function commission() { return $this->hasOne(Commission::class); }
    public function payments() { return $this->hasMany(Payment::class); }

    /** The accommodation's own owning Provider -- the earner CommissionService::applyForHotelReservation() credits. */
    public function provider()
    {
        return $this->accommodation?->provider;
    }

    // ============================== Orderable ==============================

    public function moduleCode(): string { return Modules::HOTEL; }
    public function orderCode(): string { return $this->code; }
    public function orderFranchiseId(): int { return $this->franchise_id; }
    public function orderZoneId(): ?int { return $this->zone_id; }
    public function orderCustomerId(): int { return $this->customer_id; }
    public function orderTotalPrice(): float { return (float) ($this->price_final ?? $this->price_quoted); }

    /** Hotels H2b: what the customer pays (room price + GST + fee); older rows had only the room price. */
    public function amountDue(): float { return (float) ($this->amount_due ?? $this->price_quoted); }

    public function isAwaitingOnlinePayment(): bool
    {
        return $this->status === 'pending' && $this->payment_status === 'pending' && $this->payment_method === 'online';
    }
    public function orderStatus(): string { return $this->status; }
    /**
     * Hotels step 2: the stay's coupon follows the booking. Cancelled before the stay (unpaid, declined, not accepted,
     * cancelled by anyone) = the coupon is given back; the guest did not arrive = it counts as used; stay completed =
     * used. Every path that changes the status saves this model, so one hook covers them all.
     */
    protected static function booted(): void
    {
        static::updated(function (HotelReservation $r) {
            if (! $r->wasChanged('status') || ! in_array($r->status, ['cancelled', 'completed'], true)) {
                return;
            }
            $usage = \App\Models\CouponUsage::where('hotel_reservation_id', $r->id)->where('status', 'reserved')->first();
            if (! $usage) {
                return;
            }
            $coupons = app(\App\Services\Coupons\CouponService::class);
            match (true) {
                $r->status === 'completed' => $coupons->confirm($usage),
                (bool) $r->no_show => $coupons->consume($usage),
                default => $coupons->release($usage),
            };
        });
    }
}

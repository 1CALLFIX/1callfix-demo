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
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'confirmed_at' => 'datetime',
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
    public function orderStatus(): string { return $this->status; }
}

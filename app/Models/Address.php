<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Address extends Model
{
    use HasFactory;

    protected $table = 'addresses';

    protected $fillable = [
        'user_id',
        'franchise_id',
        'zone_id',
        'label',
        'lat',
        'lng',
        'address_line',
        'landmark',
        'city',
        'pincode',
        'is_default',
        'is_parcel_only',
    ];

    protected $casts = ['is_parcel_only' => 'boolean'];

    /** The customer's own saved addresses — what the address book and every address picker list. */
    public function scopeListed($query)
    {
        return $query->where('is_parcel_only', false);
    }

    public function user() { return $this->belongsTo(User::class); }
    public function franchise() { return $this->belongsTo(Franchise::class); }
    public function zone() { return $this->belongsTo(Zone::class); }

    /**
     * True when a parcel order picks up from or delivers to this address. parcel_orders' address FKs cascade
     * on delete, so deleting it would silently delete the order (a financial record) — both delete paths
     * refuse instead (KNOWN_RISKS item 41's guard, extended to Parcel now that customers can book it).
     */
    public function usedByParcelOrder(): bool
    {
        return ParcelOrder::withTrashed()
            ->where(fn ($q) => $q->where('pickup_address_id', $this->id)->orWhere('dropoff_address_id', $this->id))
            ->exists();
    }

    /**
     * True while a live (or awaiting-payment) membership is registered to this
     * address. Deleting it would silently strip an address-locked membership of
     * its lock (the FK is nullOnDelete), so both delete paths refuse instead.
     */
    public function registersLiveMembership(): bool
    {
        return \App\Models\Subscription::where('registered_address_id', $this->id)
            ->whereIn('status', ['pending_payment', 'active', 'grace_period', 'past_due', 'paused'])
            ->exists();
    }
}
